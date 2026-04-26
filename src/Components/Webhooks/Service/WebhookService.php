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
use Psr\Log\LoggerInterface;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Mondu\MonduPayment\Components\Events\MonduOrderPendingEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderConfirmedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderDeclinedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderCancelledEvent;

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
        private readonly EventDispatcherInterface $eventDispatcher
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
                $this->monduClient->setSalesChannelId($this->salesChannelId)->registerWebhook($webhook);
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

            // Only transition order state if autoTransitionOrderState is enabled
            if ($this->configService->setSalesChannelId($this->salesChannelId)->isAutoTransitionOrderStateEnabled()) {
                $this->transitionOrderState($externalReferenceId, 'process', $context, $monduId);
            }
            
            // Determine payment transaction state based on payment method
            // Get order to check payment method
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('transactions.paymentMethod');
            $criteria->addAssociation('orderCustomer.customer');
            /** @var OrderEntity $order */
            $order = $this->orderRepository->search($criteria, $context)->first();
            
            $targetTransactionState = 'paid'; // Default to 'paid'
            
            if ($order) {
                $transaction = $order->getTransactions()->first();
                $paymentMethod = $transaction ? $transaction->getPaymentMethod() : null;
                $paymentHandlerIdentifier = $paymentMethod ? $paymentMethod->getHandlerIdentifier() : null;
                
                // Check if this is Pay Now payment method
                $isPayNow = $paymentHandlerIdentifier && str_contains($paymentHandlerIdentifier, 'MonduPayNowHandler');
                
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: handleConfirmed - determining payment state', [
                        'order_number' => $externalReferenceId,
                        'mondu_id' => $monduId,
                        'payment_handler' => $paymentHandlerIdentifier,
                        'is_pay_now' => $isPayNow
                    ]);
                }
                
                if ($isPayNow) {
                    // For Pay Now: always set to 'paid' (ignore configuration)
                    $targetTransactionState = 'paid';
                    
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Pay Now detected - setting transaction state to PAID', [
                            'order_number' => $externalReferenceId,
                            'mondu_id' => $monduId,
                            'target_state' => $targetTransactionState
                        ]);
                    }
                } else {
                    // For other payment methods: use configuration
                    $configuredState = $this->configService->setSalesChannelId($this->salesChannelId)->orderTransactionState();
                    $targetTransactionState = $configuredState;
                    
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Non-Pay Now method - using configured state', [
                            'order_number' => $externalReferenceId,
                            'mondu_id' => $monduId,
                            'configured_state' => $configuredState,
                            'target_state' => $targetTransactionState
                        ]);
                    }
                }
            }
            
            $transitionResult = $this->transitionTransactionState($externalReferenceId, $targetTransactionState, $context, $monduId);

            // Dispatch event for Flow Builder
            if ($order) {
                $event = new MonduOrderConfirmedEvent(
                    $order,
                    $monduId,
                    'confirmed',
                    $context
                );
                $this->eventDispatcher->dispatch($event, $event->getName());
            }

            return [[ 'message' => $transitionResult->last()->getTechnicalName(), 'code' => Response::HTTP_OK ], Response::HTTP_OK];
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

            // Transition to process state and process_unconfirmed
            // Protection against backward transitions is handled by isTransitionAllowed() in transitionTransactionState()
            try {
                // Only transition order state if autoTransitionOrderState is enabled
                if ($this->configService->setSalesChannelId($this->salesChannelId)->isAutoTransitionOrderStateEnabled()) {
                    $this->transitionOrderState($externalReferenceId, 'process', $context, $monduId);
                }
                $transitionResult = $this->transitionTransactionState(
                    $externalReferenceId,
                    StateMachineTransitionActions::ACTION_PROCESS_UNCONFIRMED,
                    $context,
                    $monduId
                );
                
                // Dispatch event for Flow Builder
                $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
                $criteria->addAssociation('orderCustomer.customer');
                /** @var OrderEntity $order */
                $order = $this->orderRepository->search($criteria, $context)->first();
                
                if ($order) {
                    $event = new MonduOrderPendingEvent(
                        $order,
                        $monduId,
                        'pending',
                        $context
                    );
                    $this->eventDispatcher->dispatch($event, $event->getName());
                }
                
                return [[ 'message' => $transitionResult->last()->getTechnicalName(), 'code' => Response::HTTP_OK ], Response::HTTP_OK];
                
            } catch (\Exception $e) {
                $this->log('handlePending transition failed', [
                    'error' => $e->getMessage(),
                    'externalReferenceId' => $externalReferenceId
                ]);
                throw new MonduException($e->getMessage());
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
            $orderState = $params['order_state'] ?? null;
            $topic = $params['topic'] ?? null;

            if (!$monduId || !$externalReferenceId) {
                $this->log('Required params missing', [$monduId, $externalReferenceId]);
                throw new MonduException('Required params missing');
            }
            
            // Determine if this is declined or canceled based on order_state or topic
            $isDeclined = ($orderState === 'declined') || ($topic === 'order/declined');
            $isCanceled = ($orderState === 'canceled' || $orderState === 'cancelled') || ($topic === 'order/canceled' || $topic === 'order/cancelled');

            // For both declined and canceled: cancel the order
            // This prevents order from being placed when payment is declined/canceled
            
            // Get order for event dispatching
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('deliveries.stateMachineState');
            $criteria->addAssociation('transactions.stateMachineState');
            
            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            
            // Check current transaction state to determine if this is a checkout decline or webhook decline
            $transaction = $orderEntity->getTransactions()->first();
            $currentTransactionState = $transaction ? $transaction->getStateMachineState()->getTechnicalName() : null;
            
            // IMPORTANT: Distinguish between two types of declined/cancelled:
            // 1. Through checkout (finalize): Transaction State = 'open' → Do NOT cancel order
            // 2. Through webhook (after pending): Transaction State = 'in_progress'/'unconfirmed' → Cancel order
            // 
            // If transaction is still 'open', it means user is on checkout and finalize will handle it
            // If transaction is 'in_progress' or 'unconfirmed', it means order was pending and now declined/cancelled
            $isCheckoutDecline = ($isDeclined && $currentTransactionState === 'open');
            $isCheckoutCancellation = ($isCanceled && $currentTransactionState === 'open');
            $isWebhookDecline = ($isDeclined && ($currentTransactionState === 'in_progress' || $currentTransactionState === 'unconfirmed'));
            $isWebhookCancellation = ($isCanceled && ($currentTransactionState === 'in_progress' || $currentTransactionState === 'unconfirmed'));
            
            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Webhook - analyzing decline/cancellation type', [
                    'order_number' => $externalReferenceId,
                    'mondu_id' => $monduId,
                    'currentTransactionState' => $currentTransactionState,
                    'isDeclined' => $isDeclined,
                    'isCanceled' => $isCanceled,
                    'isCheckoutDecline' => $isCheckoutDecline,
                    'isCheckoutCancellation' => $isCheckoutCancellation,
                    'isWebhookDecline' => $isWebhookDecline,
                    'isWebhookCancellation' => $isWebhookCancellation
                ]);
            }
            
            // Cancel order state if autoTransitionOrderState is enabled
            // IMPORTANT: Set sales channel ID before checking config
            // IMPORTANT: Only for CANCELLED, not for DECLINED
            
            $autoTransitionEnabled = $this->configService
                ->setSalesChannelId($this->salesChannelId)
                ->isAutoTransitionOrderStateEnabled();
            
            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Webhook handleDeclinedOrCanceled - checking autoTransitionOrderState', [
                    'order_number' => $externalReferenceId,
                    'mondu_id' => $monduId,
                    'autoTransitionOrderStateEnabled' => $autoTransitionEnabled,
                    'isDeclined' => $isDeclined,
                    'isCanceled' => $isCanceled
                ]);
            }
            
            // Cancel order state for:
            // 1. WEBHOOK CANCELLATION (after pending, not checkout cancellation)
            // 2. WEBHOOK DECLINE (after pending, not checkout decline)
            // Do NOT cancel for checkout decline/cancellation (transaction state = 'open')
            if ($autoTransitionEnabled && ($isWebhookCancellation || $isWebhookDecline)) {
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Webhook - attempting to cancel order state', [
                        'order_number' => $externalReferenceId,
                        'mondu_id' => $monduId
                    ]);
                }
                
                try {
                    $this->transitionOrderState($externalReferenceId, 'cancel', $context, $monduId);
                    
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Webhook - order state cancelled successfully', [
                            'order_number' => $externalReferenceId,
                            'mondu_id' => $monduId
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->log('Failed to cancel order state for declined/canceled payment', [
                        'externalReferenceId' => $externalReferenceId,
                        'error' => $e->getMessage()
                    ], null, 'warning');
                    // Continue anyway - transaction will still be failed
                }
            } elseif ($isCheckoutDecline || $isCheckoutCancellation) {
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Webhook - skipping order state cancellation for CHECKOUT DECLINE/CANCELLATION (handled by finalize)', [
                        'order_number' => $externalReferenceId,
                        'mondu_id' => $monduId,
                        'currentTransactionState' => $currentTransactionState,
                        'isCheckoutDecline' => $isCheckoutDecline,
                        'isCheckoutCancellation' => $isCheckoutCancellation
                    ]);
                }
            } else {
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Webhook - autoTransitionOrderState is DISABLED, order state will NOT be cancelled', [
                        'order_number' => $externalReferenceId,
                        'mondu_id' => $monduId
                    ]);
                }
            }
            
            // Transition transaction state based on declined vs cancelled
            // Declined → 'fail' (Fehlgeschlagen), Cancelled → 'cancel' (Abgebrochen)
            $transactionState = $isDeclined ? 'fail' : 'cancel';
            
            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Webhook - transitioning transaction state', [
                    'order_number' => $externalReferenceId,
                    'mondu_id' => $monduId,
                    'isDeclined' => $isDeclined,
                    'isCanceled' => $isCanceled,
                    'transactionState' => $transactionState
                ]);
            }
            
            $transitionResult = $this->transitionTransactionState($externalReferenceId, $transactionState, $context, $monduId);

            // Dispatch event for Flow Builder
            // We already have $orderEntity from the delivery check above
            $criteria = new Criteria([$orderEntity->getId()]);
            $criteria->addAssociation('orderCustomer.customer');
            /** @var OrderEntity $order */
            $order = $this->orderRepository->search($criteria, $context)->first();
            
            if ($order) {
                // Log customer data before dispatching
                if ($this->configService->isExtendedLogsEnabled()) {
                    $customer = $order->getOrderCustomer() ? $order->getOrderCustomer()->getCustomer() : null;
                    $this->logger->info('mondu.INFO: Preparing to dispatch event from webhook', [
                        'order_id' => $order->getId(),
                        'order_number' => $order->getOrderNumber(),
                        'mondu_id' => $monduId,
                        'order_state' => $orderState,
                        'topic' => $topic,
                        'isDeclined' => $isDeclined,
                        'isCanceled' => $isCanceled,
                        'has_order_customer' => $order->getOrderCustomer() !== null,
                        'has_customer' => $customer !== null,
                        'customer_id' => $customer ? $customer->getId() : 'null'
                    ]);
                }
                
                // Dispatch appropriate event based on order_state or topic
                if ($isDeclined) {
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Dispatching MonduOrderDeclinedEvent', [
                            'order_id' => $order->getId(),
                            'order_number' => $order->getOrderNumber(),
                            'mondu_id' => $monduId,
                            'order_state' => $orderState,
                            'topic' => $topic
                        ]);
                    }
                    $event = new MonduOrderDeclinedEvent(
                        $order,
                        $monduId,
                        'declined',
                        $context
                    );
                    $this->eventDispatcher->dispatch($event, $event->getName());
                    
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: MonduOrderDeclinedEvent dispatched', [
                            'event_name' => $event->getName(),
                            'event_class' => get_class($event)
                        ]);
                    }
                } elseif ($isCanceled) {
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Dispatching MonduOrderCancelledEvent', [
                            'order_id' => $order->getId(),
                            'order_number' => $order->getOrderNumber(),
                            'mondu_id' => $monduId,
                            'order_state' => $orderState,
                            'topic' => $topic
                        ]);
                    }
                    $event = new MonduOrderCancelledEvent(
                        $order,
                        $monduId,
                        'cancelled',
                        $context
                    );
                    $this->eventDispatcher->dispatch($event, $event->getName());
                    
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: MonduOrderCancelledEvent dispatched', [
                            'event_name' => $event->getName(),
                            'event_class' => get_class($event)
                        ]);
                    }
                } else {
                    // Default: if neither declined nor canceled detected, log warning
                    $this->logger->warning('mondu.WARNING: handleDeclinedOrCanceled called but neither declined nor canceled detected', [
                        'order_id' => $order->getId(),
                        'order_number' => $order->getOrderNumber(),
                        'order_state' => $orderState,
                        'topic' => $topic,
                        'isDeclined' => $isDeclined,
                        'isCanceled' => $isCanceled
                    ]);
                }
            } else {
                $this->logger->warning('mondu.WARNING: Order not found for cancelled/declined webhook', [
                    'mondu_id' => $monduId,
                    'external_reference_id' => $externalReferenceId,
                    'order_state' => $orderState,
                    'topic' => $topic
                ]);
            }

            return [[ 'message' => $transitionResult->last()->getTechnicalName(), 'code' => Response::HTTP_OK ], Response::HTTP_OK];
        } catch (MonduException $e) {
            $this->log('handleDeclinedOrCanceled Webhook Failed', [$params], $e);

            // 200 status because if order is declined from the hosted checkout it's not saved
            return [[ 'message' => $e->getMessage(), 'code' => $e->getStatusCode() ], 200];
        }
    }

    protected function transitionOrderState($externalReferenceId, $state, $context, $monduId = null): ?StateMachineStateCollection
    {
        try {
            return $this->stateMachineRegistry->transition(new Transition(
                OrderDefinition::ENTITY_NAME,
                $this->getOrderUuid($externalReferenceId, $context, $monduId),
                $state,
                'stateId'
            ), $context);
        } catch (\Exception $e) {
            $this->log('transitionOrderState Failed', [$externalReferenceId, $state], $e);
            return null;
        }
    }

    protected function transitionDeliveryState($externalReferenceId, $state, $context, $monduId = null): ?StateMachineStateCollection
    {
        try {
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('deliveries');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            $orderDeliveryId = $orderEntity->getDeliveries()->first()->getId();

            return $this->stateMachineRegistry->transition(new Transition(
                OrderDeliveryDefinition::ENTITY_NAME,
                $orderDeliveryId,
                $state,
                'stateId'
            ), $context);
        } catch (\Exception $e) {
            $this->log('transitionDeliveryState Failed', [$externalReferenceId, $state], $e);
            return null;
        }
    }

    protected function transitionTransactionState($externalReferenceId, $state, $context, $monduId = null): StateMachineStateCollection
    {
        try {
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('transactions.stateMachineState');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            $transaction = $orderEntity->getTransactions()->first();
            $orderTransactionId = $transaction->getId();
            $currentState = $transaction->getStateMachineState()->getTechnicalName();

            // Map state names to action names (Shopware expects actions, not states)
            // Note: In Shopware, most actions have same name as state (paid, not pay)
            $stateToAction = [
                'authorized' => 'authorize',
                'cancelled' => 'cancel',
                'failed' => 'fail',
                'refunded' => 'refund',
                'refunded_partially' => 'refund_partially',
                'open' => 'reopen',
                // 'paid' => 'paid' - same name, no mapping needed
            ];
            
            // Convert state to action if needed
            $action = $stateToAction[$state] ?? $state;

            // Check if transition is allowed (prevents backward transitions)
            if (!$this->isTransitionAllowed($currentState, $action)) {
                $finalStates = ['cancelled', 'failed', 'paid', 'paid_partially', 'refunded', 'refunded_partially', 'chargeback'];
                // For declined/cancelled webhooks on a final state: reopen first, then apply target
                if (in_array($currentState, $finalStates) && in_array($action, ['fail', 'cancel'])) {
                    $this->stateMachineRegistry->transition(new Transition(
                        OrderTransactionDefinition::ENTITY_NAME,
                        $orderTransactionId,
                        'reopen',
                        'stateId'
                    ), $context);
                } else {
                    $this->log('Prevented backward state transition', [
                        'externalReferenceId' => $externalReferenceId,
                        'currentState' => $currentState,
                        'attemptedState' => $state,
                        'reason' => 'State transition not allowed - would be regression'
                    ], null, 'warning');

                    return new StateMachineStateCollection([$transaction->getStateMachineState()]);
                }
            }

            // State transition allowed - proceed with logging
            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Attempting transaction state transition', [
                    'externalReferenceId' => $externalReferenceId,
                    'currentState' => $currentState,
                    'targetAction' => $action,
                    'originalState' => $state,
                    'transactionId' => $orderTransactionId
                ]);
            }
            
            $result = $this->stateMachineRegistry->transition(new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $orderTransactionId,
                $action,
                'stateId'
            ), $context);
            
            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Transaction state transition SUCCESS', [
                    'externalReferenceId' => $externalReferenceId,
                    'targetAction' => $action,
                    'result' => $result->first()?->getTechnicalName()
                ]);
            }
            
            return $result;
        } catch (MonduException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('mondu.ERROR: transitionTransactionState Failed', [
                'externalReferenceId' => $externalReferenceId,
                'targetAction' => $action ?? $state,
                'originalState' => $state,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->log('transitionTransactionState Failed', [$externalReferenceId, $action ?? $state], $e);
            throw new MonduException($e->getMessage());
        }
    }

    /**
     * Check if state transition is allowed (prevents backward transitions)
     * 
     * @param string $currentState Current transaction state
     * @param string $targetAction Target transition action
     * @return bool True if transition is allowed
     */
    protected function isTransitionAllowed(string $currentState, string $targetAction): bool
    {
        // Define state priorities (higher = more final)
        $statePriorities = [
            'open' => 0,
            'in_progress' => 1,
            'unconfirmed' => 1,
            'process_unconfirmed' => 1,
            'remind' => 1,
            'authorized' => 2,
            'paid' => 3,
            'paid_partially' => 3,
            'refunded_partially' => 4,
            'cancelled' => 4,
            'failed' => 4,
            'refunded' => 5,
            'chargeback' => 5
        ];

        // Map actions to their resulting states
        $actionToState = [
            'reopen' => 'open',
            'process' => 'in_progress',
            'process_unconfirmed' => 'unconfirmed',
            StateMachineTransitionActions::ACTION_PROCESS_UNCONFIRMED => 'unconfirmed',
            'authorize' => 'authorized',
            'paid' => 'paid',
            'paid_partially' => 'paid_partially',
            'pay' => 'paid',
            'pay_partially' => 'paid_partially',
            'cancel' => 'cancelled',
            'fail' => 'failed',
            'refund' => 'refunded',
            'refund_partially' => 'refunded_partially',
            'chargeback' => 'chargeback'
        ];

        // Get target state from action
        $targetState = $actionToState[$targetAction] ?? $targetAction;

        // Allow same state FIRST (idempotent - duplicate webhooks, finalize + webhook race condition)
        // This must be checked before final states to avoid false warnings
        if ($currentState === $targetState) {
            $this->log('Same state transition (idempotent) - skipping', [
                'currentState' => $currentState,
                'targetState' => $targetState
            ], null, 'info');
            return true;
        }

        // Get priorities
        $currentPriority = $statePriorities[$currentState] ?? 0;
        $targetPriority = $statePriorities[$targetState] ?? 0;

        // Define final states that cannot be transitioned from (except via reopen)
        $finalStates = ['cancelled', 'failed', 'paid', 'paid_partially', 'refunded', 'refunded_partially', 'chargeback'];
        
        // Allow reopen action always (manual recovery)
        if ($targetAction === 'reopen') {
            $this->log('Allowing reopen action (manual recovery)', [
                'currentState' => $currentState,
                'targetAction' => $targetAction
            ], null, 'info');
            return true;
        }

        // Prevent transitions from final states
        if (in_array($currentState, $finalStates)) {
            $this->log('Blocking transition from final state', [
                'currentState' => $currentState,
                'targetAction' => $targetAction,
                'reason' => 'Current state is final'
            ], null, 'warning');
            return false;
        }

        // Only allow forward transitions (or same priority)
        if ($targetPriority < $currentPriority) {
            $this->log('Blocking backward transition', [
                'currentState' => $currentState,
                'currentPriority' => $currentPriority,
                'targetState' => $targetState,
                'targetPriority' => $targetPriority,
                'reason' => 'Target priority is lower than current'
            ], null, 'warning');
            return false;
        }

        return true;
    }

    protected function getOrderUuid($externalReferenceId, $context, $monduId = null)
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderNumber', $externalReferenceId));
            $order = $this->orderRepository->search($criteria, $context)->first();

            if (!$order) {
                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('referenceId', $monduId));
                $orderData = $this->orderDataRepository->search($criteria, $context)->first();
                if ($orderData) return $orderData->getOrderId();
            }

            if (!$order) {
                throw new MonduException('Order not found', 404);
            }
            return $order->getId();
        } catch (MonduException $e) {
            $this->log('getOrderUuid Failed', [$externalReferenceId], $e);
            throw $e;
        } catch (\Exception $e) {
            $this->log('getOrderUuid Failed', [$externalReferenceId], $e);
            throw new MonduException($e->getMessage());
        }
    }

    protected function log($message, $data, $exception = null, $level = 'critical'): void
    {
        // Skip info and warning logs if extended logs are disabled
        if (($level === 'info' || $level === 'warning') && !$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $exceptionMessage = "";

        if ($exception != null) {
            $exceptionMessage = $exception->getMessage();
        }

        $logMessage = $message . '. (Exception: '. $exceptionMessage .')';
        
        // Call appropriate log level method
        match($level) {
            'info' => $this->logger->info($logMessage, $data),
            'warning' => $this->logger->warning($logMessage, $data),
            'error' => $this->logger->error($logMessage, $data),
            default => $this->logger->critical($logMessage, $data),
        };
    }
}
