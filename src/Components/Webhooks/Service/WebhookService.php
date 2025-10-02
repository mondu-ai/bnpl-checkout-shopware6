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
        private readonly ConfigService $configService
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
                (new Webhook('order'))->getData(),
                (new Webhook('invoice'))->getData()
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
                error_log('Missing params.');
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

            $transitionResult = $this->transitionTransactionState($externalReferenceId, 'paid', $context, $monduId);
            if ($this->configService->isAutoTransitionOrderStateEnabled()) {
                $transitionResult = $this->transitionOrderState($externalReferenceId, 'process', $context, $monduId);
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
                error_log('Required params missing');
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
                    if ($currentState !== 'open') {
                        try {
                            $this->transitionTransactionState(
                                $externalReferenceId,
                                'reopen',
                                $context,
                                $monduId
                            );
                        } catch (\Exception $e) {
                        }
                    }

                    $transitionResult = $this->transitionTransactionState(
                        $externalReferenceId,
                        StateMachineTransitionActions::ACTION_PROCESS_UNCONFIRMED,
                        $context,
                        $monduId
                    );

                    if ($this->configService->isAutoTransitionOrderStateEnabled()) {
                        $transitionResult = $this->transitionOrderState($externalReferenceId, 'process', $context, $monduId);
                    }

                    return [[ 'message' => $transitionResult->last()->getTechnicalName(), 'code' => Response::HTTP_OK ], Response::HTTP_OK];
                    
                } catch (\Exception $e) {
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
                error_log('Required params missing');
            }

            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('transactions.stateMachineState');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            $transaction = $orderEntity->getTransactions()->first();
            $currentState = $transaction->getStateMachineState()->getTechnicalName();


            if ($currentState === 'cancelled') {
                $this->log('Transaction already cancelled', [$currentState, $params]);
                return [[ 'message' => 'Transaction already cancelled: ' . $currentState, 'code' => Response::HTTP_OK ], Response::HTTP_OK];
            }

            try {
                $transitionResult = $this->transitionTransactionState($externalReferenceId, 'fail', $context, $monduId);

                try {
                    if ($this->configService->isAutoTransitionOrderStateEnabled()) {
                        $this->safeTransitionOrderCancel($externalReferenceId, $context, $monduId);
                    }
                } catch (\Exception $orderEx) {
                    $this->log('Order cancel failed, continuing', [$orderEx->getMessage()]);
                }
                
                try {
                    $this->safeTransitionDeliveryCancel($externalReferenceId, $context, $monduId);
                } catch (\Exception $deliveryEx) {
                    $this->log('Delivery cancel failed, continuing', [$deliveryEx->getMessage()]);
                }

                return [[ 'message' => $transitionResult->last()->getTechnicalName(), 'code' => Response::HTTP_OK ], Response::HTTP_OK];
                
            } catch (\Exception $e) {
                $this->log('Direct transaction fail failed, trying reopen approach', [$currentState, $e->getMessage()]);

                try {
                    if ($currentState !== 'open') {
                        $this->log('Two-step cancel: first reopening to open state', [$currentState]);
                        $this->transitionTransactionState($externalReferenceId, 'reopen', $context, $monduId);
                    }

                    $this->log('Attempting transaction fail after reopen', [$currentState]);
                    $transitionResult = $this->transitionTransactionState($externalReferenceId, 'fail', $context, $monduId);

                    try {
                        $this->safeTransitionOrderCancel($externalReferenceId, $context, $monduId);
                    } catch (\Exception $orderEx) {
                        $this->log('Order cancel failed after reopen, continuing', [$orderEx->getMessage()]);
                    }
                    
                    try {
                        $this->safeTransitionDeliveryCancel($externalReferenceId, $context, $monduId);
                    } catch (\Exception $deliveryEx) {
                        $this->log('Delivery cancel failed after reopen, continuing', [$deliveryEx->getMessage()]);
                    }
                    
                    return [[ 'message' => $transitionResult->last()->getTechnicalName(), 'code' => Response::HTTP_OK ], Response::HTTP_OK];
                    
                } catch (\Exception $e2) {
                    $this->log('All transition attempts failed - graceful fallback', [$currentState, $e2->getMessage(), $params]);
                    return [[ 'message' => 'Cancel webhook processed (transition failed): ' . $currentState, 'code' => Response::HTTP_OK ], Response::HTTP_OK];
                }
            }

        } catch (MonduException $e) {
            $this->log('handleDeclinedOrCanceled Webhook Failed', [$params], $e);

            return [[ 'message' => $e->getMessage(), 'code' => $e->getStatusCode() ], 200];
        }
    }

    protected function transitionOrderState($externalReferenceId, $state, $context, $monduId = null): ?StateMachineStateCollection
    {
        try {
            
            if ($state === 'cancel') {
            }
            
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
            
            if ($state === 'cancel') {
            }
            
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
            error_log($e->getMessage());
        }
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
                error_log('Order not found', 404);
            }
            return $order->getId();
        } catch (MonduException $e) {
            $this->log('getOrderUuid Failed', [$externalReferenceId], $e);
            throw $e;
        } catch (\Exception $e) {
            $this->log('getOrderUuid Failed', [$externalReferenceId], $e);
            error_log($e->getMessage());
        }
    }

    protected function log($message, $data, $exception = null): void
    {
        $exceptionMessage = "";

        if ($exception != null) {
            $exceptionMessage = $exception->getMessage();
        }

        $this->logger->critical(
            $message . '. (Exception: '. $exceptionMessage .')',
            $data
        );
    }

    /**
     * Safely transition order state to cancel with fallback to available transitions
     */
    protected function safeTransitionOrderCancel($externalReferenceId, $context, $monduId = null): void
    {
        try {
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
            $this->transitionDeliveryState($externalReferenceId, 'cancel', $context, $monduId);
        } catch (\Exception $e) {
            $this->log('Delivery cancel failed, trying alternative', [$e->getMessage()]);
        }
    }
}
