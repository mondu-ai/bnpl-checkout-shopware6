<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler;

use Shopware\Core\Checkout\Payment\PaymentException;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\Events\MonduOrderCancelledEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderDeclinedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Mondu\MonduPayment\Services\OrderServices\AbstractOrderLinesService;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Log\LoggerInterface;

class MonduHandler implements AsynchronousPaymentHandlerInterface
{
    const PAYMENT_STATE_SUCCESS = 'success';
    const RESPONSE_STATE_CONFIRMED = 'confirmed';
    const RESPONSE_STATE_PENDING = 'pending';
    const ORDER_TRANSACTION_STATE_PAID = 'paid';
    const ORDER_TRANSACTION_STATE_AUTHORIZED = 'authorized';

    public function __construct(
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly MonduClient $monduClient,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $orderDataRepository,
        private readonly ConfigService $configService,
        private readonly AbstractOrderLinesService $orderLinesService,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    /**
     * @throws AsyncPaymentProcessException
     */
    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        try {
            $redirectUrl = $this->createOrder($transaction, $salesChannelContext);
        } catch (\Exception $e) {
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage()
            );
        }

        return new RedirectResponse($redirectUrl);
    }

    /**
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request                       $request
     * @param SalesChannelContext           $salesChannelContext
     * @return void
     */
    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        try {
            $transactionId = $transaction->getOrderTransaction()->getId();
            $paymentState = $request->query->getAlpha('payment');
            $context = $salesChannelContext->getContext();

            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: finalize() called with paymentState', [
                    'paymentState' => $paymentState,
                    'order_id' => $transaction->getOrder()->getId(),
                    'order_number' => $transaction->getOrder()->getOrderNumber(),
                    'transaction_id' => $transactionId,
                    'all_query_params' => $request->query->all()
                ]);
            }

        if ($paymentState === self::PAYMENT_STATE_SUCCESS) {
            $paymentOrderUuid = $request->query->get('order_uuid');

            $confirmResponseState = $this->monduClient->setSalesChannelId(
                $salesChannelContext->getSalesChannelId()
            )->confirmOrder(
                $paymentOrderUuid,
                ['external_reference_id' => $transaction->getOrder()->getOrderNumber()]
            );

            if (!$this->isOrderConfirmed($confirmResponseState)) {
                $this->logger->error('mondu.ERROR: Order confirmation failed', [
                    'order_number' => $transaction->getOrder()->getOrderNumber(),
                    'order_uuid' => $paymentOrderUuid,
                    'confirmResponseState' => $confirmResponseState,
                    'expected' => 'confirmed or pending'
                ]);
                
                throw PaymentException::customerCanceled(
                    $transactionId,
                    'Order not confirmed.'
                );
            }

            $this->monduClient
                 ->setSalesChannelId($salesChannelContext->getSalesChannelId())
                 ->updateExternalInfo(
                     $paymentOrderUuid,
                     ['external_reference_id' => $transaction->getOrder()->getOrderNumber()]
                 );
            
            $this->createLocalOrder($transaction, $paymentOrderUuid, $salesChannelContext);

            $orderTransactionState = $this->configService->setSalesChannelId($salesChannelContext->getSalesChannelId())->orderTransactionState();
            
            $paymentMethod = $transaction->getOrderTransaction()->getPaymentMethod();
            $paymentHandlerIdentifier = $paymentMethod ? $paymentMethod->getHandlerIdentifier() : '';
            $isPayNow = str_contains($paymentHandlerIdentifier, 'MonduPayNowHandler');

            try {
                if ($confirmResponseState == self::RESPONSE_STATE_PENDING) {
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Mondu returned pending - setting to processUnconfirmed', [
                            'order_number' => $transaction->getOrder()->getOrderNumber(),
                            'confirmResponseState' => $confirmResponseState,
                            'isPayNow' => $isPayNow
                        ]);
                    }
                    $this->transactionStateHandler->processUnconfirmed($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                }
                else if ($isPayNow) {
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Pay Now with confirmed - setting to paid', [
                            'order_number' => $transaction->getOrder()->getOrderNumber(),
                            'confirmResponseState' => $confirmResponseState
                        ]);
                    }
                    $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                } else if ($orderTransactionState == self::ORDER_TRANSACTION_STATE_AUTHORIZED) {
                    $this->transactionStateHandler->authorize($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                } else {
                    $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                }
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'cannot be edited') !== false || 
                    strpos($e->getMessage(), 'was cancelled') !== false) {
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->warning('mondu.INFO: Order was cancelled during transaction state change, this should not affect the customer', [
                            'order_id' => $transaction->getOrder()->getId(),
                            'order_number' => $transaction->getOrder()->getOrderNumber(),
                            'intended_state' => $orderTransactionState,
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    throw $e;
                }
            }
        } else {
            // Move transaction to in_progress first (open → in_progress).
            // For declined: let PaymentService call fail() by throwing asyncFinalizeInterrupted.
            // For cancelled: let PaymentService call cancel() by throwing customerCanceled.
            // This avoids double state transitions since PaymentService always transitions after catch.
            try {
                $this->transactionStateHandler->process($transaction->getOrderTransaction()->getId(), $context);
            } catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'cannot be edited') !== false ||
                    strpos($e->getMessage(), 'was cancelled') !== false ||
                    stripos($e->getMessage(), 'Illegal transition') !== false) {
                    return;
                } else {
                    throw $e;
                }
            }

            $paymentOrderUuid = $request->query->get('order_uuid');
            $order = $transaction->getOrder();

            if ($paymentState === 'declined') {
                $event = new MonduOrderDeclinedEvent(
                    $order,
                    $paymentOrderUuid,
                    'declined',
                    $salesChannelContext->getContext()
                );
                $this->eventDispatcher->dispatch($event, $event->getName());

                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Dispatched MonduOrderDeclinedEvent from finalize()', [
                        'order_id' => $order->getId(),
                        'order_number' => $order->getOrderNumber(),
                        'mondu_id' => $paymentOrderUuid,
                        'event_name' => $event->getName()
                    ]);
                }

                // Throw asyncFinalizeInterrupted so PaymentService calls fail() → "failed" state.
                // Do NOT throw customerCanceled here — that causes PaymentService to call cancel() instead.
                throw PaymentException::asyncFinalizeInterrupted(
                    $transactionId,
                    'Payment declined by Mondu.'
                );
            } elseif ($paymentState === 'cancelled') {
                $event = new MonduOrderCancelledEvent(
                    $order,
                    $paymentOrderUuid,
                    'cancelled',
                    $salesChannelContext->getContext()
                );
                $this->eventDispatcher->dispatch($event, $event->getName());

                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Dispatched MonduOrderCancelledEvent from finalize()', [
                        'order_id' => $order->getId(),
                        'order_number' => $order->getOrderNumber(),
                        'mondu_id' => $paymentOrderUuid,
                        'event_name' => $event->getName()
                    ]);
                }

                throw PaymentException::customerCanceled(
                    $transactionId,
                    'Canceled payment in Mondu Checkout.'
                );
            }
        }
        } catch (\Throwable $globalEx) {
            if ($paymentState === 'declined' || $paymentState === 'cancelled') {
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Payment cancelled/declined by user', [
                        'paymentState' => $paymentState,
                        'order_id' => $transaction->getOrder()->getId() ?? 'unknown',
                        'order_number' => $transaction->getOrder()->getOrderNumber() ?? 'unknown',
                        'exception' => get_class($globalEx),
                        'message' => $globalEx->getMessage(),
                        'code' => $globalEx->getCode(),
                        'file' => $globalEx->getFile(),
                        'line' => $globalEx->getLine(),
                        'trace' => $globalEx->getTraceAsString()
                    ]);
                }
            } else {
                $this->logger->error('mondu.ERROR: Unexpected exception in finalize()', [
                    'exception' => get_class($globalEx),
                    'message' => $globalEx->getMessage(),
                    'code' => $globalEx->getCode(),
                    'file' => $globalEx->getFile(),
                    'line' => $globalEx->getLine(),
                    'order_id' => $transaction->getOrder()->getId() ?? 'unknown',
                    'order_number' => $transaction->getOrder()->getOrderNumber() ?? 'unknown',
                    'paymentState' => $paymentState ?? 'unknown',
                    'trace' => $globalEx->getTraceAsString()
                ]);
            }
            
            throw $globalEx;
        }
    }

    private function createOrder(AsyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext): string
    {
        $orderData = $this->getOrderData($transaction, $salesChannelContext);
        $monduOrder = $this->monduClient->setSalesChannelId($salesChannelContext->getSalesChannelId())->createOrder($orderData);

        if ($monduOrder === null || !isset($monduOrder['hosted_checkout_url'])) {
            $order = $transaction->getOrder();
            $this->logger->error('mondu.ERROR: Failed to create Mondu order - invalid response', [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'mondu_response' => $monduOrder
            ]);
            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'Failed to create Mondu order: Invalid response from Mondu API'
            );
        }

        $this->saveEarlyOrderData($transaction, $monduOrder, $salesChannelContext);

        return $monduOrder['hosted_checkout_url'];
    }

    private function saveEarlyOrderData($transaction, $monduOrder, $salesChannelContext): void
    {
        try {
            $order = $transaction->getOrder();

            if ($monduOrder === null || !isset($monduOrder['uuid'])) {
                $this->logger->error('mondu.ERROR: Failed to save early order data - missing uuid in Mondu response', [
                    'order_id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'mondu_response' => $monduOrder
                ]);
                return;
            }
            
            $this->orderDataRepository->upsert([
                [
                    OrderDataEntity::FIELD_ORDER_ID => $order->getId(),
                    OrderDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                    OrderDataEntity::FIELD_REFERENCE_ID => $monduOrder['uuid'],
                    OrderDataEntity::FIELD_EXTERNAL_REFERENCE_ID => $monduOrder['external_reference_id'] ?? null,
                    OrderDataEntity::FIELD_ORDER_STATE => $monduOrder['state'] ?? 'pending',
                    OrderDataEntity::FIELD_VIBAN => null,
                    OrderDataEntity::FIELD_DURATION => 0,
                    OrderDataEntity::FIELD_IS_SUCCESSFUL => false,
                ]
            ], $salesChannelContext->getContext());
            
            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Early order data saved', [
                    'order_id' => $order->getId(),
                    'mondu_uuid' => $monduOrder['uuid'],
                    'external_reference_id' => $monduOrder['external_reference_id'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('mondu.ERROR: Failed to save early order data', [
                'error' => $e->getMessage(),
                'mondu_uuid' => $monduOrder['uuid'] ?? null,
            ]);
        }
    }

    protected function getOrderData(AsyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext)
    {
        $order = $transaction->getOrder();
        $returnUrl = $transaction->getReturnUrl();
        $orderTransaction = $transaction->getOrderTransaction();

        $shippingAddress = $order->getDeliveries()->getShippingAddress()->first();
        $paymentMethod = MethodHelper::shortNameToMonduName($orderTransaction->getPaymentMethod()->getShortName());

        $externalReferenceId = uniqid('M_SW6_');

        if ($this->configService->setSalesChannelId($salesChannelContext->getSalesChannelId())->isExtendedLogsEnabled()) {
            $this->logger->info('mondu.INFO: Generated external_reference_id for Mondu order', [
                'external_reference_id' => $externalReferenceId,
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'payment_method' => $paymentMethod,
                'total_amount' => $order->getPrice()->getTotalPrice(),
            ]);
        }

        $addressAdditionHandling1 = $this->configService->getHandlingAddressAdditionalField1();
        $addressAdditionHandling2 = $this->configService->getHandlingAddressAdditionalField2();

        $billingAddress = $order->getBillingAddress();
        $addressAddition1 = $billingAddress->getAdditionalAddressLine1();
        $addressAddition2 = $billingAddress->getAdditionalAddressLine2();   
        $addressLine1 = $billingAddress->getStreet();
        $addressLine2 = null;
        $shippingAddressLine2 = null;

        if ($addressAdditionHandling1 === 'addtoaddressline1' && !empty($addressAddition1)) {
            $addressLine1 .= ' ' . $addressAddition1;
        } elseif ($addressAdditionHandling1 === 'addtoaddressline2' && !empty($addressAddition1)) {
            $addressLine2 = $addressAddition1;
        }

        if ($addressAdditionHandling2 === 'addtoaddressline2' && !empty($addressAddition2)) {
            $addressLine2 .= ' ' . $addressAddition2;
        } elseif ($addressAdditionHandling2 === 'addtoaddressline1' && !empty($addressAddition2)) {
            $addressLine1 .= ' ' . $addressAddition2;
        }

        $shippingAddress = $order->getDeliveries()->getShippingAddress()->first();
        $shippingAddressLine1 = $shippingAddress->getStreet();
        $shippingAddressAddition1 = $shippingAddress->getAdditionalAddressLine1();
        $shippingAddressAddition2 = $shippingAddress->getAdditionalAddressLine2();

        if ($addressAdditionHandling1 === 'addtoaddressline1' && !empty($shippingAddressAddition1)) {
            $shippingAddressLine1 .= ' ' . $shippingAddressAddition1;
        } elseif ($addressAdditionHandling1 === 'addtoaddressline2' && !empty($shippingAddressAddition1)) {
            $shippingAddressLine2 = $shippingAddressAddition1;
        }

        if ($addressAdditionHandling2 === 'addtoaddressline2' && !empty($shippingAddressAddition2)) {
            $shippingAddressLine2 .= ' ' . $shippingAddressAddition2;
        } elseif ($addressAdditionHandling2 === 'addtoaddressline1' && !empty($shippingAddressAddition2)) {
            $shippingAddressLine1 .= ' ' . $shippingAddressAddition2;
        }

        return [
            'currency' => $order->getCurrency()->getIsoCode(),
            'state_flow' => 'authorization_flow',
            'payment_method' => $paymentMethod,
            'success_url' => $returnUrl . '&payment=success',
            'cancel_url' => $returnUrl . '&payment=cancelled',
            'declined_url' => $returnUrl . '&payment=declined',
            'external_reference_id' => $externalReferenceId,
            'gross_amount_cents' => round($order->getPrice()->getTotalPrice() * 100),
            'buyer' => $this->buildBuyerPayload($order, $addressLine1, $addressLine2),
            'billing_address' => [
                'address_line1' => $addressLine1,
                'address_line2' => $addressLine2,
                'city' => $order->getBillingAddress()->getCity(),
                'country_code' => $order->getBillingAddress()->getCountry()->getIso(),
                'zip_code' => $order->getBillingAddress()->getZipCode(),
            ],
            'shipping_address' => [
                'address_line1' => $shippingAddressLine1,
                'address_line2' => $shippingAddressLine2,
                'city' => $shippingAddress->getCity(),
                'country_code' => $shippingAddress->getCountry()->getIso(),
                'zip_code' => $shippingAddress->getZipCode(),
            ],
            'lines' => $this->orderLinesService->getLines($order, $salesChannelContext->getContext())
        ];
    }

    private function buildBuyerPayload(object $order, string $addressLine1, ?string $addressLine2): array
    {
        $orderCustomer = $order->getOrderCustomer();
        $billingAddress = $order->getBillingAddress();
        $customer = $orderCustomer->getCustomer();

        $buyer = [
            'email' => $orderCustomer->getEmail(),
            'first_name' => $orderCustomer->getFirstname(),
            'last_name' => $orderCustomer->getLastName(),
            'company_name' => $orderCustomer->getCompany(),
            'phone' => $billingAddress->getPhoneNumber(),
            'address_line1' => $addressLine1,
            'address_line2' => $addressLine2,
            'zip_code' => $billingAddress->getZipCode(),
            'is_registered' => !$customer->getGuest(),
            'external_reference_id' => $customer->getCustomerNumber(),
            'account_created_at' => $customer->getCreatedAt(),
            'account_updated_at' => $customer->getUpdatedAt(),
        ];

        $vatId = $billingAddress->getVatId();
        if (($vatId === null || $vatId === '') && method_exists($orderCustomer, 'getVatIds')) {
            $vatIds = $orderCustomer->getVatIds();
            $vatId = is_array($vatIds) ? ($vatIds[0] ?? null) : null;
        }
        if (($vatId === null || $vatId === '') && method_exists($orderCustomer, 'getVatId')) {
            $vatId = $orderCustomer->getVatId();
        }
        if ($vatId !== null && $vatId !== '') {
            $buyer['vat_number'] = (string) $vatId;
        }

        return $buyer;
    }

    public function createLocalOrder($transaction, $orderUuid, $salesChannelContext) {
        $order = $transaction->getOrder();
        $monduOrder = $this->monduClient->setSalesChannelId($salesChannelContext->getSalesChannelId())->getMonduOrder($orderUuid);
        
        if (!$monduOrder) {
            throw new AsyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'Could not fetch Mondu Order.');
        }

        $context = $salesChannelContext->getContext();

        $this->orderDataRepository->upsert([
            [
                OrderDataEntity::FIELD_ORDER_ID => $order->getId(),
                OrderDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                OrderDataEntity::FIELD_REFERENCE_ID => $monduOrder['uuid'],
                OrderDataEntity::FIELD_ORDER_STATE => $monduOrder['state'],
                OrderDataEntity::FIELD_VIBAN => $monduOrder['bank_account']['iban'],
                OrderDataEntity::FIELD_DURATION => $monduOrder['authorized_net_term'],
                OrderDataEntity::FIELD_EXTERNAL_REFERENCE_ID => $monduOrder['external_reference_id'] ?? null,
                OrderDataEntity::FIELD_IS_SUCCESSFUL => true,
            ]
        ], $context);

        $this->deleteStaleOrderData($order->getId(), $monduOrder['uuid'], $context);
    }

    private function deleteStaleOrderData(string $orderId, string $activeReferenceId, \Shopware\Core\Framework\Context $context): void
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $orderId));
            $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
                new EqualsFilter('referenceId', $activeReferenceId),
            ]));

            $ids = $this->orderDataRepository->searchIds($criteria, $context)->getIds();

            if (empty($ids)) {
                return;
            }

            $deletePayload = array_map(fn($id) => ['id' => $id], $ids);
            $this->orderDataRepository->delete($deletePayload, $context);

            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Deleted stale order data entries', [
                    'order_id' => $orderId,
                    'active_reference_id' => $activeReferenceId,
                    'deleted_count' => count($ids),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('mondu.WARNING: Failed to delete stale order data', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function isOrderConfirmed($confirmResponseState)
    {
        return in_array($confirmResponseState, [self::RESPONSE_STATE_CONFIRMED, self::RESPONSE_STATE_PENDING]);
    }
}

