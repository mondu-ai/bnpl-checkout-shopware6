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

            $this->transitionOrderState($externalReferenceId, 'process', $context, $monduId);
            $transitionResult = $this->transitionTransactionState($externalReferenceId, 'paid', $context, $monduId);

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

            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('transactions.stateMachineState');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            $transaction = $orderEntity->getTransactions()->first();
            $currentState = $transaction->getStateMachineState()->getTechnicalName();

            // Final states that should not be transitioned (protection against backward transitions)
            $finalStates = ['paid', 'paid_partially', 'cancelled', 'failed', 'refunded', 'refunded_partially', 'chargeback'];
            
            if (in_array($currentState, $finalStates)) {
                $this->log('Pending webhook blocked - transaction already in final state', [
                    'currentState' => $currentState,
                    'externalReferenceId' => $externalReferenceId,
                    'reason' => 'Cannot transition from final state back to pending'
                ]);
                return [[ 'message' => 'Transaction already in final state: ' . $currentState, 'code' => Response::HTTP_OK ], Response::HTTP_OK];
            }

            // Attempt transition for non-final states
            try {
                $this->transitionOrderState($externalReferenceId, 'process', $context, $monduId);

                // Only try to transition to process_unconfirmed directly (no reopen needed)
                // The isTransitionAllowed() method will handle any edge cases
                $transitionResult = $this->transitionTransactionState(
                    $externalReferenceId,
                    StateMachineTransitionActions::ACTION_PROCESS_UNCONFIRMED,
                    $context,
                    $monduId
                );
                
                return [[ 'message' => $transitionResult->last()->getTechnicalName(), 'code' => Response::HTTP_OK ], Response::HTTP_OK];
                
            } catch (\Exception $e) {
                $this->log('Pending transition failed, treating webhook as success', [
                    'currentState' => $currentState,
                    'error' => $e->getMessage(),
                    'externalReferenceId' => $externalReferenceId
                ]);
                return [[ 'message' => 'Pending webhook processed (transition failed): ' . $currentState, 'code' => Response::HTTP_OK ], Response::HTTP_OK];
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

            // Try to transition order and delivery states, but don't fail if transition is not possible
            // These methods already handle exceptions gracefully and return null on failure
            $this->transitionOrderState($externalReferenceId, 'cancelled', $context, $monduId);
            $this->transitionDeliveryState($externalReferenceId, 'cancelled', $context, $monduId);
            
            // Main focus: transition transaction to fail state (this is the critical one)
            $transitionResult = $this->transitionTransactionState($externalReferenceId, 'fail', $context, $monduId);

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

            // Check if transition is allowed (prevents backward transitions)
            if (!$this->isTransitionAllowed($currentState, $state)) {
                $this->log('Prevented backward state transition', [
                    'externalReferenceId' => $externalReferenceId,
                    'currentState' => $currentState,
                    'attemptedState' => $state,
                    'reason' => 'State transition not allowed - would be regression'
                ]);
                
                // Return current state without transition
                return new StateMachineStateCollection([$transaction->getStateMachineState()]);
            }

            $this->log('Allowing state transition', [
                'externalReferenceId' => $externalReferenceId,
                'currentState' => $currentState,
                'newState' => $state
            ]);

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
            ]);
            return true;
        }

        // Prevent transitions from final states
        if (in_array($currentState, $finalStates)) {
            $this->log('Blocking transition from final state', [
                'currentState' => $currentState,
                'targetAction' => $targetAction,
                'reason' => 'Current state is final'
            ]);
            return false;
        }

        // Allow same state (idempotent - duplicate webhooks)
        if ($currentState === $targetState) {
            return true;
        }

        // Only allow forward transitions (or same priority)
        if ($targetPriority < $currentPriority) {
            $this->log('Blocking backward transition', [
                'currentState' => $currentState,
                'currentPriority' => $currentPriority,
                'targetState' => $targetState,
                'targetPriority' => $targetPriority,
                'reason' => 'Target priority is lower than current'
            ]);
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
}
