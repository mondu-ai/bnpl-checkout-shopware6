<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Webhooks\Service;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateCollection;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Symfony\Component\HttpFoundation\Response;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Mondu\MonduPayment\Components\Webhooks\Model\Webhook;
use Mondu\MonduPayment\Components\Webhooks\Service\ShopUrlService;
use Psr\Log\LoggerInterface;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Components\Events\Service\MonduEventDispatcher;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;

class WebhookService
{
    /**
     * @var null
     */
    private $salesChannelId;

    public function __construct(
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger,
        private readonly MonduClient $monduClient,
        private readonly EntityRepository $orderDataRepository,
        private readonly ConfigService $configService,
        private readonly ShopUrlService $shopUrlService,
        private readonly MonduEventDispatcher $monduEventDispatcher
    ) {
        $this->salesChannelId = null;
    }

    public function setSalesChannelId($salesChannelId = null): static
    {
        $this->salesChannelId = $salesChannelId;

        return $this;
    }

    public function getSecret($key)
    {
        try {
            $keys = $this->monduClient->setSalesChannelId($this->salesChannelId)->getWebhooksSecret($key);

            if (isset($keys['webhook_secret']))
            {
                $this->configService->setSalesChannelId($this->salesChannelId)->setWebhooksSecret($keys['webhook_secret']);
            }

            return $keys['webhook_secret'] ?? false;
        } catch (MonduException $e) {
            $this->log('Get Webhook Secret Failed', [], $e);
            return false;
        }
    }

    public function register(): bool
    {
        try {
            $webhooks = [
                (new Webhook('order', $this->shopUrlService, $this->salesChannelId))->getData(),
                (new Webhook('invoice', $this->shopUrlService, $this->salesChannelId))->getData()
            ];

            foreach ($webhooks as $webhook) {
                $result = $this->monduClient->setSalesChannelId($this->salesChannelId)->registerWebhook($webhook);
                
                // Check if webhook was already registered
                if (isset($result['status']) && $result['status'] === 'already_registered') {
                    $this->log('Webhook already registered (skipped)', [
                        'topic' => $webhook['topic'],
                        'address' => $webhook['address']
                    ]);
                } else {
                    $this->log('Webhook registered successfully', [
                        'topic' => $webhook['topic'],
                        'address' => $webhook['address']
                    ]);
                }
            }
            
            return true;
        } catch (MonduException $e) {
            $this->log('register Webhook Failed', [], $e);
            return false;
        }
    }

    public function handleConfirmed($params, $context): array
    {
        try {
            $viban = $params['bank_account']['iban'] ?? null;
            $monduId = $params['order_uuid'];
            $externalReferenceId = $params['external_reference_id'];

            if (!$viban || !$externalReferenceId) {
                throw new MonduException('Missing params.');
            }

            // Update vIBAN
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('referenceId', $monduId));

            $orderDataId = $this->orderDataRepository->searchIds($criteria, $context)->firstId();

            $this->orderDataRepository->update([
                [
                    'id' => $orderDataId,
                    'viban' => $viban
                ]
            ], $context);

            $order = $this->getOrderByExternalReferenceId($externalReferenceId, $context);
            if ($order) {
                $previousStatus = $this->getCurrentMonduOrderState($order);
                $this->log('Dispatching Mondu Order Confirmed Event', [$externalReferenceId, $monduId, $previousStatus]);
                $this->monduEventDispatcher->dispatchOrderConfirmed(
                    $order,
                    $monduId,
                    $previousStatus ?? 'unknown',
                    $context
                );
                $this->log('Mondu Order Confirmed Event dispatched successfully', [$externalReferenceId]);
            }

            // Always transition transaction state to paid
            $transitionResult = $this->transitionTransactionState($externalReferenceId, 'paid', $context, $monduId);
            
            // Additionally transition order state if auto-transition is enabled
            if ($this->configService->isAutoTransitionOrderStateEnabled()) {
                try {
                    $this->transitionOrderState($externalReferenceId, 'process', $context, $monduId);
                } catch (\Exception $e) {
                    $this->log('Order state transition failed, but transaction paid succeeded', [$e->getMessage()]);
                }
            }

            return [[ 'message' => 'success', 'code' => Response::HTTP_OK ], Response::HTTP_OK];
        } catch (MonduException $e) {
            $this->log('handleConfirmed Webhook Failed', [$params], $e);
            return [[ 'message' => $e->getMessage(), 'code' => $e->getStatusCode() ], $e->getStatusCode()];
        }
    }

    public function handlePending($params, $context): array
    {
        try {
            $externalReferenceId = $params['external_reference_id'];
            $monduId = $params['order_uuid'];

            if (!$externalReferenceId || !$monduId) {
                throw new MonduException('Required params missing');
            }

            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('transactions.stateMachineState');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            $transaction = $orderEntity->getTransactions()->first();
            $currentState = $transaction->getStateMachineState()->getTechnicalName();

            // Only attempt transition if not already in paid or completed state
            $finalStates = ['paid', 'cancelled', 'refunded', 'refunded_partially', 'chargeback'];
            
            if (!in_array($currentState, $finalStates)) {
                try {
                    if ($this->configService->isAutoTransitionOrderStateEnabled()) {
                        $this->transitionOrderState($externalReferenceId, 'process', $context, $monduId);
                    }

                    if ($currentState !== 'open') {
                        try {
                            $this->log('Two-step transition: first reopening to open state', [$currentState]);
                            $this->transitionTransactionState(
                                $externalReferenceId,
                                'reopen',
                                $context,
                                $monduId
                            );
                        } catch (\Exception $e) {
                            $this->log('Reopen transition failed', [$currentState, $e->getMessage()]);
                        }
                    }

                    $order = $this->getOrderByExternalReferenceId($externalReferenceId, $context);
                    if ($order) {
                        $previousStatus = $this->getCurrentMonduOrderState($order);
                        $this->log('Dispatching Mondu Order Pending Event', [$externalReferenceId, $monduId, $previousStatus]);
                        $this->monduEventDispatcher->dispatchOrderPending(
                            $order,
                            $monduId,
                            $previousStatus ?? 'unknown',
                            $context
                        );
                        $this->log('Mondu Order Pending Event dispatched successfully', [$externalReferenceId]);
                    }
                    
                    $transitionResult = $this->transitionTransactionState(
                        $externalReferenceId,
                        StateMachineTransitionActions::ACTION_PROCESS_UNCONFIRMED,
                        $context,
                        $monduId
                    );
                    
                    return [[ 'message' => $transitionResult->last()->getTechnicalName(), 'code' => Response::HTTP_OK ], Response::HTTP_OK];
                    
                } catch (\Exception $e) {
                    $this->log('Two-step transition failed, treating pending webhook as success', [$currentState, $e->getMessage(), $params]);
                    
                    try {
                        $order = $this->getOrderByExternalReferenceId($externalReferenceId, $context);
                        if ($order) {
                            $previousStatus = $this->getCurrentMonduOrderState($order);
                            $this->log('Dispatching Mondu Order Pending Event (after transition failure)', [$externalReferenceId, $monduId]);
                            $this->monduEventDispatcher->dispatchOrderPending(
                                $order,
                                $monduId,
                                $previousStatus ?? 'unknown',
                                $context
                            );
                        }
                    } catch (\Exception $eventEx) {
                        $this->log('Failed to dispatch Mondu Order Pending Event', [$eventEx->getMessage()]);
                    }
                    
                    return [[ 'message' => 'Pending webhook processed (transition failed): ' . $currentState, 'code' => Response::HTTP_OK ], Response::HTTP_OK];
                }
            } else {
                $this->log('Pending webhook received for transaction already in final state', [$currentState, $params]);
                return [[ 'message' => 'Transaction already in final state: ' . $currentState, 'code' => Response::HTTP_OK ], Response::HTTP_OK];
            }

        } catch (MonduException $e) {
            $this->log('handlePending Webhook Failed', [$params], $e);
            return [[ 'message' => $e->getMessage(), 'code' => $e->getStatusCode() ], $e->getStatusCode()];
        }
    }

    public function handleDeclinedOrCanceled($params, $context): array
    {
        try {
            $monduId = $params['order_uuid'];
            $externalReferenceId = $params['external_reference_id'];
            $orderState = $params['order_state'];

            if (!$monduId || !$externalReferenceId || !$orderState) {
                $this->log('Required params missing', [$monduId, $externalReferenceId, $orderState]);
                throw new MonduException('Required params missing');
            }

            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('transactions.stateMachineState');
            $criteria->addAssociation('stateMachineState');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            
            $transaction = $orderEntity->getTransactions()->first();
            $currentState = $transaction->getStateMachineState()->getTechnicalName();
            $orderCurrentState = $orderEntity->getStateMachineState()->getTechnicalName();

            $this->log('Processing cancel/decline webhook', [
                'externalReferenceId' => $externalReferenceId,
                'orderNumber' => $orderEntity->getOrderNumber(),
                'transactionState' => $currentState,
                'orderState' => $orderCurrentState,
                'webhookState' => $orderState
            ]);

            // Check if transaction is already cancelled
            if ($currentState === 'cancelled') {
                $this->log('Transaction already cancelled', [$currentState, $params]);
                return [[ 'message' => 'Transaction already cancelled: ' . $currentState, 'code' => Response::HTTP_OK ], Response::HTTP_OK];
            }

            // Check if order is already cancelled to prevent "cannot be edited afterwards" error
            if ($orderCurrentState === 'cancelled') {
                $this->log('Order already cancelled, skipping transitions', ['orderState' => $orderCurrentState, 'transactionState' => $currentState, $params]);
                return [[ 'message' => 'Order already cancelled: ' . $orderCurrentState, 'code' => Response::HTTP_OK ], Response::HTTP_OK];
            }
            
            $order = $this->getOrderByExternalReferenceId($externalReferenceId, $context);
            if ($order) {
                $previousStatus = $this->getCurrentMonduOrderState($order);
                try {
                    if ($orderState === 'declined') {
                        $this->log('Dispatching Mondu Order Declined Event', [$externalReferenceId, $monduId, $previousStatus]);
                        $this->monduEventDispatcher->dispatchOrderDeclined(
                            $order,
                            $monduId,
                            $previousStatus ?? 'unknown',
                            $context
                        );
                        $this->log('Mondu Order Declined Event dispatched successfully', [$externalReferenceId]);
                    } elseif ($orderState === 'cancelled') {
                        $this->log('Dispatching Mondu Order Cancelled Event', [$externalReferenceId, $monduId, $previousStatus]);
                        $this->monduEventDispatcher->dispatchOrderCancelled(
                            $order,
                            $monduId,
                            $previousStatus ?? 'unknown',
                            $context
                        );
                        $this->log('Mondu Order Cancelled Event dispatched successfully', [$externalReferenceId]);
                    }
                } catch (\Exception $eventEx) {
                    // Catch "cannot be edited" errors from event dispatchers
                    if (strpos($eventEx->getMessage(), 'cannot be edited') !== false) {
                        $this->log('Order was already cancelled during event dispatch, continuing', [$externalReferenceId, $eventEx->getMessage()]);
                    } else {
                        $this->log('Event dispatch failed', [$externalReferenceId, $eventEx->getMessage()]);
                    }
                }
            }
            try {                $this->log('Attempting direct transaction fail transition', [$currentState]);
                $transitionResult = $this->transitionTransactionState($externalReferenceId, 'fail', $context, $monduId);

                if ($this->configService->isAutoTransitionOrderStateEnabled()) {
                    try {
                        $this->log('Transaction fail succeeded, trying order cancel', [$currentState]);
                        $this->safeTransitionOrderCancel($externalReferenceId, $context, $monduId);
                    } catch (\Exception $orderEx) {
                        $this->log('Order cancel failed, continuing', [$orderEx->getMessage()]);
                    }
                    
                    // Only cancel delivery if payment was in unconfirmed state
                    if (in_array($currentState, ['unconfirmed', 'process_unconfirmed'])) {
                        try {
                            $this->log('Trying delivery cancel (payment was unconfirmed)', [$currentState]);
                            $this->safeTransitionDeliveryCancel($externalReferenceId, $context, $monduId);
                        } catch (\Exception $deliveryEx) {
                            $this->log('Delivery cancel failed, continuing', [$deliveryEx->getMessage()]);
                        }
                    } else {
                        $this->log('Skipping delivery cancel - payment state is not unconfirmed', [$currentState]);
                    }
                }

                return [[ 'message' => 'success', 'code' => Response::HTTP_OK ], Response::HTTP_OK];
                
            } catch (\Exception $e) {
                $this->log('Direct transaction fail failed, trying reopen approach', [$currentState, $e->getMessage()]);

                try {
                    if ($currentState !== 'open') {
                        $this->log('Two-step cancel: first reopening to open state', [$currentState]);
                        $this->transitionTransactionState($externalReferenceId, 'reopen', $context, $monduId);
                    }

                    $this->log('Attempting transaction fail after reopen', [$currentState]);
                    $transitionResult = $this->transitionTransactionState($externalReferenceId, 'fail', $context, $monduId);

                    if ($this->configService->isAutoTransitionOrderStateEnabled()) {
                        try {
                            $this->safeTransitionOrderCancel($externalReferenceId, $context, $monduId);
                        } catch (\Exception $orderEx) {
                            $this->log('Order cancel failed after reopen, continuing', [$orderEx->getMessage()]);
                        }
                        
                        // Only cancel delivery if payment was in unconfirmed state
                        if (in_array($currentState, ['unconfirmed', 'process_unconfirmed'])) {
                            try {
                                $this->log('Trying delivery cancel after reopen (payment was unconfirmed)', [$currentState]);
                                $this->safeTransitionDeliveryCancel($externalReferenceId, $context, $monduId);
                            } catch (\Exception $deliveryEx) {
                                $this->log('Delivery cancel failed after reopen, continuing', [$deliveryEx->getMessage()]);
                            }
                        } else {
                            $this->log('Skipping delivery cancel after reopen - payment state is not unconfirmed', [$currentState]);
                        }
                    }
                    
                    return [[ 'message' => 'success', 'code' => Response::HTTP_OK ], Response::HTTP_OK];
                    
                } catch (\Exception $e2) {
                    $this->log('All transition attempts failed - graceful fallback', [$currentState, $e2->getMessage(), $params]);
                    return [[ 'message' => 'Cancel webhook processed (transition failed): ' . $currentState, 'code' => Response::HTTP_OK ], Response::HTTP_OK];
                }
            }

        } catch (\Throwable $e) {
            // Catch all errors including Shopware's "cannot be edited" OrderException
            if (strpos($e->getMessage(), 'cannot be edited') !== false || strpos($e->getMessage(), 'was cancelled') !== false) {
                $this->log('Order was already cancelled, webhook processed gracefully', [$externalReferenceId ?? 'unknown', $e->getMessage()]);
                return [[ 'message' => 'Order already cancelled', 'code' => Response::HTTP_OK ], Response::HTTP_OK];
            }
            
            // Handle MonduException separately for proper status code
            if ($e instanceof MonduException) {
                $this->log('handleDeclinedOrCanceled Webhook Failed', [$params], $e);
                return [[ 'message' => $e->getMessage(), 'code' => $e->getStatusCode() ], 200];
            }
            
            // Log other unexpected errors
            $this->log('handleDeclinedOrCanceled Webhook Failed (unexpected error)', [$params, $e->getMessage()], $e);
            return [[ 'message' => 'Webhook processing failed', 'code' => Response::HTTP_INTERNAL_SERVER_ERROR ], 200];
        }
    }

    protected function transitionOrderState($externalReferenceId, $state, $context, $monduId = null): ?StateMachineStateCollection
    {
        try {            
            // Check if order is already cancelled before attempting any transition
            $orderId = $this->getOrderUuid($externalReferenceId, $context, $monduId);
            $criteria = new Criteria([$orderId]);
            $criteria->addAssociation('stateMachineState');
            
            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            if ($orderEntity) {
                $orderCurrentState = $orderEntity->getStateMachineState()->getTechnicalName();
                
                if ($orderCurrentState === 'cancelled') {
                    $this->log('Order already cancelled, skipping ORDER transition', [$externalReferenceId, $state, $orderCurrentState]);
                    return null;
                }
            }
            
            if ($state === 'cancel') {            }
            
            try {
                return $this->stateMachineRegistry->transition(new Transition(
                    OrderDefinition::ENTITY_NAME,
                    $orderId,
                    $state,
                    'stateId'
                ), $context);
            } catch (\Throwable $transitionEx) {
                // Catch Shopware's "cannot be edited" exception
                if (strpos($transitionEx->getMessage(), 'cannot be edited') !== false || 
                    strpos($transitionEx->getMessage(), 'was cancelled') !== false) {
                    $this->log('Order was already cancelled during transition, skipping gracefully', [$externalReferenceId, $state, $transitionEx->getMessage()]);
                    return null;
                }
                throw $transitionEx;
            }
        } catch (\Exception $e) {
            $this->log('transitionOrderState Failed', [$externalReferenceId, $state], $e);
            return null;
        }
    }

    protected function transitionDeliveryState($externalReferenceId, $state, $context, $monduId = null): ?StateMachineStateCollection
    {
        try {            
            if ($state === 'cancel') {            }
            
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('deliveries');
            $criteria->addAssociation('stateMachineState');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            
            // Check if order is already cancelled before attempting delivery transition
            if ($orderEntity) {
                $orderCurrentState = $orderEntity->getStateMachineState()->getTechnicalName();
                
                if ($orderCurrentState === 'cancelled') {
                    $this->log('Order already cancelled, skipping DELIVERY transition', [$externalReferenceId, $state, $orderCurrentState]);
                    return null;
                }
            }
            
            $orderDeliveryId = $orderEntity->getDeliveries()->first()->getId();

            try {
                return $this->stateMachineRegistry->transition(new Transition(
                    OrderDeliveryDefinition::ENTITY_NAME,
                    $orderDeliveryId,
                    $state,
                    'stateId'
                ), $context);
            } catch (\Throwable $transitionEx) {
                // Catch Shopware's "cannot be edited" exception
                if (strpos($transitionEx->getMessage(), 'cannot be edited') !== false || 
                    strpos($transitionEx->getMessage(), 'was cancelled') !== false) {
                    $this->log('Order was already cancelled during delivery transition, skipping gracefully', [$externalReferenceId, $state, $transitionEx->getMessage()]);
                    return null;
                }
                throw $transitionEx;
            }
        } catch (\Exception $e) {
            $this->log('transitionDeliveryState Failed', [$externalReferenceId, $state], $e);
            return null;
        }
    }

    protected function transitionTransactionState($externalReferenceId, $state, $context, $monduId = null): StateMachineStateCollection
    {
        try {
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('transactions');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            $orderTransactionId = $orderEntity->getTransactions()->first()->getId();

            return $this->stateMachineRegistry->transition(new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $orderTransactionId,
                $state,
                'stateId'
            ), $context);
        } catch (MonduException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->log('transitionTransactionState Failed', [$externalReferenceId, $state], $e);
            throw new MonduException($e->getMessage());
        }
    }

    protected function getOrderUuid($externalReferenceId, $context, $monduId = null)
    {
        try {
            // 1. Try to find by order number
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderNumber', $externalReferenceId));
            $order = $this->orderRepository->search($criteria, $context)->first();

            if ($order) {
                return $order->getId();
            }

            // 2. Try to find by external_reference_id in mondu_order_data
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('externalReferenceId', $externalReferenceId));
            $orderData = $this->orderDataRepository->search($criteria, $context)->first();
            if ($orderData) {
                $this->log('Order found by external_reference_id', [
                    'external_reference_id' => $externalReferenceId,
                    'order_id' => $orderData->getOrderId()
                ]);
                return $orderData->getOrderId();
            }

            // 3. Try to find by mondu reference_id (UUID from Mondu)
            if ($monduId) {
                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('referenceId', $monduId));
                $orderData = $this->orderDataRepository->search($criteria, $context)->first();
                if ($orderData) {
                    return $orderData->getOrderId();
                }
            }

            // Not found by any method
            throw new MonduException('Order not found', 404);
            
        } catch (MonduException $e) {
            $this->log('getOrderUuid Failed', [$externalReferenceId], $e);
            throw $e;
        } catch (\Exception $e) {
            $this->log('getOrderUuid Failed', [$externalReferenceId], $e);
            throw new MonduException($e->getMessage());
        }
    }

    /**
     * Get current Mondu order state from order
     */
    protected function getCurrentMonduOrderState(OrderEntity $order): ?string
    {
        try {
            $orderData = $order->getExtension(OrderExtension::EXTENSION_NAME);
            if ($orderData instanceof OrderDataEntity) {
                return $orderData->getOrderState();
            }
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get order by external reference ID
     */
    protected function getOrderByExternalReferenceId(string $externalReferenceId, $context): ?OrderEntity
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderNumber', $externalReferenceId));
            $criteria->addAssociation(OrderExtension::EXTENSION_NAME);

            $order = $this->orderRepository->search($criteria, $context)->first();
            return $order instanceof OrderEntity ? $order : null;
        } catch (\Exception $e) {
            $this->log('getOrderByExternalReferenceId Failed', [$externalReferenceId], $e);
            return null;
        }
    }

    protected function log($message, $data, $exception = null): void
    {
        $logMessage = $message;

        if ($exception != null) {
            // Always log errors/exceptions
            $logMessage = 'mondu.CRITICAL: ' . $logMessage . '. (Exception: '. $exception->getMessage() .')';
            $this->logger->critical($logMessage, $data);
        } else {
            // Only log informational messages if extended logs are enabled
            if ($this->configService->isExtendedLogsEnabled()) {
                $logMessage = 'mondu.INFO: ' . $logMessage;
                $this->logger->info($logMessage, $data);
            }
        }
    }

    /**
     * Safely transition order state to cancel with fallback to available transitions
     */
    protected function safeTransitionOrderCancel($externalReferenceId, $context, $monduId = null): void
    {
        try {
            // Check if order is already cancelled before attempting transition
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('stateMachineState');
            
            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            $orderCurrentState = $orderEntity->getStateMachineState()->getTechnicalName();
            
            if ($orderCurrentState === 'cancelled') {
                $this->log('Order already cancelled, skipping order cancel transition', [$externalReferenceId, $orderCurrentState]);
                return;
            }
            
            $this->log('Trying order cancel transition', [$externalReferenceId]);
            $this->transitionOrderState($externalReferenceId, 'cancel', $context, $monduId);
        } catch (\Exception $e) {
            $this->log('Order cancel failed, trying alternative', [$e->getMessage()]);
        }
    }

    /**
     * Safely transition delivery state to cancel with fallback to available transitions
     */
    protected function safeTransitionDeliveryCancel($externalReferenceId, $context, $monduId = null): void
    {
        try {
            // Check if order is already cancelled before attempting delivery transition
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('stateMachineState');
            
            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            $orderCurrentState = $orderEntity->getStateMachineState()->getTechnicalName();
            
            if ($orderCurrentState === 'cancelled') {
                $this->log('Order already cancelled, skipping delivery cancel transition', [$externalReferenceId, $orderCurrentState]);
                return;
            }
            
            $this->log('Trying delivery cancel transition', [$externalReferenceId]);
            $this->transitionDeliveryState($externalReferenceId, 'cancel', $context, $monduId);
        } catch (\Exception $e) {
            $this->log('Delivery cancel failed, trying alternative', [$e->getMessage()]);
        }
    }
}
