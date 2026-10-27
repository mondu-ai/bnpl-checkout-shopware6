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
use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderStates;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Shopware\Core\Framework\Context;

class WebhookService
{
    private ?string $salesChannelId;

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

            if (isset($keys['webhook_secret'])) {
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

            if ($orderDataId !== null) {
                $this->orderDataRepository->update([
                    [
                        'id' => $orderDataId,
                        'viban' => $viban
                    ]
                ], $context);
            }

            // An order created in the admin is final only now, once the buyer confirmed it by
            // email. Mondu still carries the reference sent at creation, so the order number
            // goes out once more through update_external_info (no confirm call in this flow).
            $asyncOrderData = $this->findAsyncOrderData($monduId, $context);
            if ($asyncOrderData !== null) {
                $this->confirmAsyncOrder($asyncOrderData, $monduId, $context);
            }

            // Only transition order state if autoTransitionOrderState is enabled
            if ($this->configService->setSalesChannelId($this->salesChannelId)->isAutoTransitionOrderStateEnabled()) {
                $this->transitionOrderState($externalReferenceId, 'process', $context, $monduId);
            }

            // Determine payment transaction state based on payment method
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('transactions.paymentMethod');
            $criteria->addAssociation('orderCustomer.customer');
            /** @var OrderEntity $order */
            $order = $this->orderRepository->search($criteria, $context)->first();

            $targetTransactionState = 'paid';

            if ($order) {
                $transaction = $order->getTransactions()->last();
                $paymentMethod = $transaction ? $transaction->getPaymentMethod() : null;
                $paymentHandlerIdentifier = $paymentMethod ? $paymentMethod->getHandlerIdentifier() : null;

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
                    $targetTransactionState = 'paid';
                } else {
                    $configuredState = $this->configService->setSalesChannelId($this->salesChannelId)->orderTransactionState();
                    $targetTransactionState = $configuredState;
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

            return [[ 'message' => $transitionResult->last()?->getTechnicalName() ?? 'ok', 'code' => Response::HTTP_OK ], Response::HTTP_OK];
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

            try {
                // Only transition order state if autoTransitionOrderState is enabled
                if ($this->configService->setSalesChannelId($this->salesChannelId)->isAutoTransitionOrderStateEnabled()) {
                    $this->transitionOrderState($externalReferenceId, 'process', $context, $monduId);
                }

                // An order created in the admin goes to "Payment review" while Mondu checks it.
                $asyncOrderData = $this->findAsyncOrderData($monduId, $context);
                if ($asyncOrderData !== null) {
                    $this->updateOrderData($asyncOrderData, [OrderDataEntity::FIELD_ORDER_STATE => 'pending'], $context);
                }

                $transitionResult = $this->transitionTransactionState(
                    $externalReferenceId,
                    $asyncOrderData !== null
                        ? AsyncOrderStates::STATE_PAYMENT_REVIEW
                        : StateMachineTransitionActions::ACTION_PROCESS_UNCONFIRMED,
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

                return [[ 'message' => $transitionResult->last()?->getTechnicalName() ?? 'ok', 'code' => Response::HTTP_OK ], Response::HTTP_OK];

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

    /**
     * order/authorized: Mondu approved an order created in the admin and asked the buyer to
     * confirm it by email. The payment waits in "Pending buyer confirmation" until the
     * order/confirmed webhook.
     *
     * A hosted checkout order passes through authorized as well, while the buyer is still
     * on the way back to the shop and finalize() handles it. Those are left alone.
     */
    public function handleAuthorized($params, $context): array
    {
        try {
            $externalReferenceId = $params['external_reference_id'] ?? null;
            $monduId = $params['order_uuid'] ?? null;

            if (!$externalReferenceId || !$monduId) {
                throw new MonduException('Required params missing');
            }

            $asyncOrderData = $this->findAsyncOrderData($monduId, $context);
            if ($asyncOrderData === null) {
                return [[ 'message' => 'Not an order created in the admin, nothing to do', 'code' => Response::HTTP_OK ], Response::HTTP_OK];
            }

            $this->updateOrderData($asyncOrderData, [
                OrderDataEntity::FIELD_ORDER_STATE => 'authorized',
            ] + $this->readAuthorizedNetTerm($asyncOrderData, $monduId), $context);

            $transitionResult = $this->transitionTransactionState(
                $externalReferenceId,
                AsyncOrderStates::STATE_PENDING_BUYER_CONFIRMATION,
                $context,
                $monduId
            );

            return [[ 'message' => $transitionResult->last()?->getTechnicalName() ?? 'ok', 'code' => Response::HTTP_OK ], Response::HTTP_OK];
        } catch (MonduException $e) {
            $this->log('handleAuthorized Webhook Failed', [$params], $e);
            return [[ 'message' => $e->getMessage(), 'code' => $e->getStatusCode() ], $e->getStatusCode()];
        }
    }

    /**
     * The Mondu data of an order created in the admin, or null for any other order.
     */
    protected function findAsyncOrderData(string $monduId, Context $context): ?OrderDataEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('referenceId', $monduId));
        $criteria->addFilter(new EqualsFilter('orderFlow', AsyncOrderStates::FLOW_ASYNC));
        $criteria->addAssociation('order');

        /** @var OrderDataEntity|null $orderData */
        $orderData = $this->orderDataRepository->search($criteria, $context)->first();

        return $orderData;
    }

    /**
     * Sends the final order number to Mondu and records the confirmation.
     *
     * A failed call fails the webhook, so Mondu retries it, the same as in the Magento module.
     */
    private function confirmAsyncOrder(OrderDataEntity $orderData, string $monduId, Context $context): void
    {
        $order = $orderData->getOrder();
        $response = $this->monduClient
            ->setSalesChannelId($order->getSalesChannelId())
            ->updateExternalInfo($monduId, ['external_reference_id' => $order->getOrderNumber()]);

        if ($response === null) {
            throw new MonduException('Could not send the order number to Mondu (update_external_info).', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $this->updateOrderData($orderData, [
            OrderDataEntity::FIELD_ORDER_STATE => 'confirmed',
            OrderDataEntity::FIELD_IS_SUCCESSFUL => true,
            OrderDataEntity::FIELD_EXTERNAL_REFERENCE_ID => $order->getOrderNumber(),
        ] + $this->readAuthorizedNetTerm($orderData, $monduId), $context);
    }

    /**
     * The net term Mondu authorized, which the create_async answer does not carry yet.
     *
     * @return array<string, int>
     */
    private function readAuthorizedNetTerm(OrderDataEntity $orderData, string $monduId): array
    {
        if ((int) $orderData->getDuration() > 0) {
            return [];
        }

        $monduOrder = $this->monduClient
            ->setSalesChannelId($orderData->getOrder()->getSalesChannelId())
            ->getMonduOrder($monduId);
        $netTerm = (int) ($monduOrder['authorized_net_term'] ?? 0);

        return $netTerm > 0 ? [OrderDataEntity::FIELD_DURATION => $netTerm] : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateOrderData(OrderDataEntity $orderData, array $data, Context $context): void
    {
        $this->orderDataRepository->update([
            [OrderDataEntity::FIELD_ID => $orderData->getId()] + $data,
        ], $context);
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

            // Get order for event dispatching
            $criteria = new Criteria([$this->getOrderUuid($externalReferenceId, $context, $monduId)]);
            $criteria->addAssociation('deliveries.stateMachineState');
            $criteria->addAssociation('transactions.stateMachineState');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();

            // Check current transaction state to determine if this is a checkout decline or webhook decline
            $transaction = $orderEntity->getTransactions()->last();
            $currentTransactionState = $transaction ? $transaction->getStateMachineState()->getTechnicalName() : null;

            // An order created in the admin never passes the hosted checkout, so the transaction
            // state says nothing about where the decline came from: such an order is always
            // cancelled. Its Mondu state is recorded first, so that the cancellation is not
            // sent back to Mondu (see TransitionSubscriber).
            $asyncOrderData = $this->findAsyncOrderData($monduId, $context);
            if ($asyncOrderData !== null) {
                $this->updateOrderData($asyncOrderData, [
                    OrderDataEntity::FIELD_ORDER_STATE => $isDeclined ? 'declined' : 'canceled',
                ], $context);
            }

            $isCheckoutDecline = $asyncOrderData === null && ($isDeclined && $currentTransactionState === 'open');
            $isCheckoutCancellation = $asyncOrderData === null && ($isCanceled && $currentTransactionState === 'open');
            $isWebhookDecline = $isDeclined && ($asyncOrderData !== null || $currentTransactionState === 'in_progress' || $currentTransactionState === 'unconfirmed');
            $isWebhookCancellation = $isCanceled && ($asyncOrderData !== null || $currentTransactionState === 'in_progress' || $currentTransactionState === 'unconfirmed');

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

            // Store Mondu's state before the order is cancelled below, so that TransitionSubscriber
            // does not ask Mondu to cancel an order Mondu declined or cancelled itself.
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('referenceId', $monduId));
            $orderDataIds = $this->orderDataRepository->searchIds($criteria, $context)->getIds();

            if ($orderDataIds !== []) {
                $this->orderDataRepository->update(array_map(static fn ($id) => [
                    'id' => $id,
                    'orderState' => $isDeclined ? 'declined' : 'canceled'
                ], $orderDataIds), $context);
            }

            $autoTransitionEnabled = $this->configService
                ->setSalesChannelId($this->salesChannelId)
                ->isAutoTransitionOrderStateEnabled();

            if (($autoTransitionEnabled || $asyncOrderData !== null) && ($isWebhookCancellation || $isWebhookDecline)) {
                try {
                    $this->transitionOrderState($externalReferenceId, 'cancel', $context, $monduId);
                } catch (\Exception $e) {
                    $this->log('Failed to cancel order state for declined/canceled payment', [
                        'externalReferenceId' => $externalReferenceId,
                        'error' => $e->getMessage()
                    ], null, 'warning');
                }
            } elseif ($isCheckoutDecline || $isCheckoutCancellation) {
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Webhook - skipping order state cancellation for CHECKOUT DECLINE/CANCELLATION (handled by finalize)', [
                        'order_number' => $externalReferenceId,
                        'mondu_id' => $monduId,
                        'currentTransactionState' => $currentTransactionState,
                    ]);
                }
            }

            // Declined → 'fail', Cancelled → 'cancel'
            $transactionState = $isDeclined ? 'fail' : 'cancel';

            $transitionResult = $this->transitionTransactionState($externalReferenceId, $transactionState, $context, $monduId);

            // Dispatch event for Flow Builder
            $criteria = new Criteria([$orderEntity->getId()]);
            $criteria->addAssociation('orderCustomer.customer');
            /** @var OrderEntity $order */
            $order = $this->orderRepository->search($criteria, $context)->first();

            if ($order) {
                if ($isDeclined) {
                    $event = new MonduOrderDeclinedEvent(
                        $order,
                        $monduId,
                        'declined',
                        $context
                    );
                    $this->eventDispatcher->dispatch($event, $event->getName());
                } elseif ($isCanceled) {
                    $event = new MonduOrderCancelledEvent(
                        $order,
                        $monduId,
                        'cancelled',
                        $context
                    );
                    $this->eventDispatcher->dispatch($event, $event->getName());
                }
            }

            return [[ 'message' => $transitionResult->last()?->getTechnicalName() ?? 'ok', 'code' => Response::HTTP_OK ], Response::HTTP_OK];
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
            $transaction = $orderEntity->getTransactions()->last();
            $orderTransactionId = $transaction->getId();
            $currentState = $transaction->getStateMachineState()->getTechnicalName();

            // Map state names to action names
            $stateToAction = [
                'authorized' => 'authorize',
                'cancelled' => 'cancel',
                'failed' => 'fail',
                'refunded' => 'refund',
                'refunded_partially' => 'refund_partially',
                'open' => 'reopen',
            ];

            $action = $stateToAction[$state] ?? $state;

            // Check if transition is allowed (prevents backward transitions)
            if (!$this->isTransitionAllowed($currentState, $action)) {
                $finalStates = ['cancelled', 'failed', 'paid', 'paid_partially', 'refunded', 'refunded_partially', 'chargeback'];
                // For declined/cancelled webhooks on a final state: reopen first, then apply target.
                // Note: reopen + target is not atomic — if a concurrent webhook changes the state
                // between the two calls, the second transition will throw IllegalTransitionException,
                // which we catch gracefully below.
                if (in_array($currentState, $finalStates) && in_array($action, ['fail', 'cancel'])) {
                    try {
                        $this->stateMachineRegistry->transition(new Transition(
                            OrderTransactionDefinition::ENTITY_NAME,
                            $orderTransactionId,
                            'reopen',
                            'stateId'
                        ), $context);
                    } catch (\Exception $e) {
                        $this->logger->warning('mondu.WARNING: reopen before ' . $action . ' failed (concurrent modification?)', [
                            'externalReferenceId' => $externalReferenceId,
                            'currentState' => $currentState,
                            'error' => $e->getMessage()
                        ]);
                        return new StateMachineStateCollection([$transaction->getStateMachineState()]);
                    }
                } else {
                    return new StateMachineStateCollection([$transaction->getStateMachineState()]);
                }
            }

            $result = $this->stateMachineRegistry->transition(new Transition(
                OrderTransactionDefinition::ENTITY_NAME,
                $orderTransactionId,
                $action,
                'stateId'
            ), $context);

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
     */
    protected function isTransitionAllowed(string $currentState, string $targetAction): bool
    {
        $statePriorities = [
            'open' => 0,
            'in_progress' => 1,
            'unconfirmed' => 1,
            'process_unconfirmed' => 1,
            AsyncOrderStates::STATE_PAYMENT_REVIEW => 1,
            AsyncOrderStates::STATE_PENDING_BUYER_CONFIRMATION => 1,
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

        $targetState = $actionToState[$targetAction] ?? $targetAction;

        // Allow same state (idempotent)
        if ($currentState === $targetState) {
            return true;
        }

        $currentPriority = $statePriorities[$currentState] ?? 0;
        $targetPriority = $statePriorities[$targetState] ?? 0;

        $finalStates = ['cancelled', 'failed', 'paid', 'paid_partially', 'refunded', 'refunded_partially', 'chargeback'];

        // Allow reopen action always
        if ($targetAction === 'reopen') {
            return true;
        }

        // Prevent transitions from final states
        if (in_array($currentState, $finalStates)) {
            return false;
        }

        // Only allow forward transitions
        if ($targetPriority < $currentPriority) {
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
        if ($level === 'info' && !$this->configService->isExtendedLogsEnabled()) {
            return;
        }

        $exceptionMessage = "";

        if ($exception != null) {
            $exceptionMessage = $exception->getMessage();
        }

        $logMessage = $message . '. (Exception: '. $exceptionMessage .')';

        match($level) {
            'info' => $this->logger->info($logMessage, $data),
            'warning' => $this->logger->warning($logMessage, $data),
            'error' => $this->logger->error($logMessage, $data),
            default => $this->logger->critical($logMessage, $data),
        };
    }
}
