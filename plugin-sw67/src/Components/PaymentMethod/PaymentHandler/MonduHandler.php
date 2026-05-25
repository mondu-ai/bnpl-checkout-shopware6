<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler;

use Shopware\Core\Checkout\Payment\PaymentException;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\Events\MonduOrderCancelledEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderDeclinedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Mondu\MonduPayment\Services\OrderServices\AbstractOrderLinesService;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Psr\Log\LoggerInterface;

class MonduHandler extends AbstractPaymentHandler
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
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly LoggerInterface $logger,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {}

    public function supports(
        PaymentHandlerType $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        return $type === PaymentHandlerType::PAYMENT;
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        try {
            $redirectUrl = $this->createOrder($transaction, $context);
        } catch (\Exception $e) {
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransaction()->getId(),
                'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage()
            );
        }

        return new RedirectResponse($redirectUrl);
    }

    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $transactionId = $transaction->getOrderTransactionId();
        $paymentState = $request->query->getAlpha('payment');
        $order = null;

        try {
            $orderTransaction = $this->getOrderTransaction($transactionId, $context);
            $order = $orderTransaction->getOrder();
            $salesChannelId = $order->getSalesChannelId();

            if ($this->configService->setSalesChannelId($salesChannelId)->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: finalize() called with paymentState', [
                    'paymentState' => $paymentState,
                    'order_id' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'transaction_id' => $transactionId,
                    'all_query_params' => $request->query->all()
                ]);
            }

            if ($paymentState === self::PAYMENT_STATE_SUCCESS) {
                $paymentOrderUuid = $request->query->get('order_uuid');

                $confirmResponseState = $this->monduClient->setSalesChannelId($salesChannelId)->confirmOrder(
                    $paymentOrderUuid,
                    ['external_reference_id' => $order->getOrderNumber()]
                );

                if (!$this->isOrderConfirmed($confirmResponseState)) {
                    $this->logger->error('mondu.ERROR: Order confirmation failed', [
                        'order_number' => $order->getOrderNumber(),
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
                     ->setSalesChannelId($salesChannelId)
                     ->updateExternalInfo(
                         $paymentOrderUuid,
                         ['external_reference_id' => $order->getOrderNumber()]
                     );

                $this->createLocalOrder($transaction, $paymentOrderUuid, $context);

                $orderTransactionState = $this->configService->setSalesChannelId($salesChannelId)->orderTransactionState();

                $paymentMethod = $orderTransaction->getPaymentMethod();
                $paymentHandlerIdentifier = $paymentMethod ? $paymentMethod->getHandlerIdentifier() : '';
                $isPayNow = str_contains($paymentHandlerIdentifier, 'MonduPayNowHandler');

                try {
                    if ($confirmResponseState == self::RESPONSE_STATE_PENDING) {
                        // Mondu requires manual review — always set to "Unconfirmed" regardless of configured state
                        if ($this->configService->isExtendedLogsEnabled()) {
                            $this->logger->info('mondu.INFO: Mondu returned pending - setting to processUnconfirmed', [
                                'order_number' => $order->getOrderNumber(),
                                'confirmResponseState' => $confirmResponseState,
                                'orderTransactionState' => $orderTransactionState,
                                'isPayNow' => $isPayNow
                            ]);
                        }
                        $this->transactionStateHandler->processUnconfirmed($transactionId, $context);
                    } elseif ($isPayNow) {
                        if ($this->configService->isExtendedLogsEnabled()) {
                            $this->logger->info('mondu.INFO: Pay Now with confirmed - setting to paid', [
                                'order_number' => $order->getOrderNumber(),
                                'confirmResponseState' => $confirmResponseState
                            ]);
                        }
                        $this->transactionStateHandler->paid($transactionId, $context);
                    } elseif ($orderTransactionState == self::ORDER_TRANSACTION_STATE_AUTHORIZED) {
                        $this->transactionStateHandler->authorize($transactionId, $context);
                    } else {
                        $this->transactionStateHandler->paid($transactionId, $context);
                    }
                } catch (\Throwable $e) {
                    if (strpos($e->getMessage(), 'cannot be edited') !== false ||
                        strpos($e->getMessage(), 'was cancelled') !== false) {
                        $this->logger->warning('mondu.WARNING: Order was cancelled during transaction state change, this should not affect the customer', [
                            'order_id' => $order->getId(),
                            'order_number' => $order->getOrderNumber(),
                            'error' => $e->getMessage()
                        ]);
                    } else {
                        throw $e;
                    }
                }
            } else {
                try {
                    // Go via process first (required by SW state machine), then:
                    // - declined → fail ("Fehlgeschlagen") so merchant can distinguish from cancelled
                    // - cancelled → cancel ("Abgebrochen")
                    $this->transactionStateHandler->process($transactionId, $context);
                    if ($paymentState === 'declined') {
                        $this->transactionStateHandler->fail($transactionId, $context);
                    } else {
                        $this->transactionStateHandler->cancel($transactionId, $context);
                    }
                } catch (\Throwable $e) {
                    if (strpos($e->getMessage(), 'cannot be edited') !== false ||
                        strpos($e->getMessage(), 'was cancelled') !== false) {
                        return;
                    } else {
                        throw $e;
                    }
                }

                $paymentOrderUuid = $request->query->get('order_uuid');

                if ($paymentState === 'declined') {
                    $request->getSession()->set('mondu_payment_declined', true);

                    $event = new MonduOrderDeclinedEvent(
                        $order,
                        $paymentOrderUuid,
                        'declined',
                        $context
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
                } elseif ($paymentState === 'cancelled') {
                    $event = new MonduOrderCancelledEvent(
                        $order,
                        $paymentOrderUuid,
                        'cancelled',
                        $context
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
                }

                throw PaymentException::customerCanceled(
                    $transactionId,
                    'Canceled/declined payment in Mondu Checkout.'
                );
            }
        } catch (\Throwable $globalEx) {
            if ($paymentState === 'declined' || $paymentState === 'cancelled') {
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Payment cancelled/declined by user', [
                        'paymentState' => $paymentState,
                        'order_id' => $order?->getId() ?? 'unknown',
                        'order_number' => $order?->getOrderNumber() ?? 'unknown',
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
                    'order_id' => $order?->getId() ?? 'unknown',
                    'order_number' => $order?->getOrderNumber() ?? 'unknown',
                    'paymentState' => $paymentState ?? 'unknown',
                    'trace' => $globalEx->getTraceAsString()
                ]);
            }

            throw $globalEx;
        }
    }

    private function createOrder(PaymentTransactionStruct $transaction, Context $context): string
    {
        $orderData = $this->getOrderData($transaction, $context);
        $orderTransaction = $this->getOrderTransaction($transaction->getOrderTransactionId(), $context);
        $order = $orderTransaction->getOrder();
        $salesChannelId = $order->getSalesChannelId();
        $monduOrder = $this->monduClient->setSalesChannelId($salesChannelId)->createOrder($orderData);

        if ($monduOrder === null || !isset($monduOrder['hosted_checkout_url'])) {
            $this->logger->error('mondu.ERROR: Failed to create Mondu order - invalid response', [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'mondu_response' => $monduOrder
            ]);
            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                'Failed to create Mondu order: Invalid response from Mondu API'
            );
        }

        $this->saveEarlyOrderData($order, $monduOrder, $context);

        return $monduOrder['hosted_checkout_url'];
    }

    private function saveEarlyOrderData($order, $monduOrder, Context $context): void
    {
        try {
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
            ], $context);

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

    protected function getOrderData(PaymentTransactionStruct $transaction, Context $context)
    {
        $orderTransaction = $this->getOrderTransaction($transaction->getOrderTransactionId(), $context);
        $order = $orderTransaction->getOrder();
        $returnUrl = $transaction->getReturnUrl();

        $paymentMethod = MethodHelper::shortNameToMonduName($orderTransaction->getPaymentMethod()->getShortName());

        $externalReferenceId = uniqid('M_SW6_');
        $salesChannelId = $order->getSalesChannelId();

        if ($this->configService->setSalesChannelId($salesChannelId)->isExtendedLogsEnabled()) {
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

        $shippingAddress = $order->getDeliveries()->first()?->getShippingOrderAddress();
        // Use billing address as fallback if shipping address is not available
        $addressForShipping = $shippingAddress ?? $billingAddress;

        if ($this->configService->isExtendedLogsEnabled()) {
            $this->logger->info('mondu.INFO: Shipping address resolved', [
                'order_id'              => $order->getId(),
                'order_number'          => $order->getOrderNumber(),
                'using_fallback'        => $shippingAddress === null,
                'shipping_street'       => $addressForShipping->getStreet(),
                'shipping_city'         => $addressForShipping->getCity(),
                'shipping_zip'          => $addressForShipping->getZipCode(),
                'shipping_country'      => $addressForShipping->getCountry()?->getIso(),
                'billing_street'        => $billingAddress->getStreet(),
                'billing_city'          => $billingAddress->getCity(),
                'billing_zip'           => $billingAddress->getZipCode(),
                'addresses_match'       => $addressForShipping->getStreet() === $billingAddress->getStreet()
                    && $addressForShipping->getZipCode() === $billingAddress->getZipCode(),
            ]);
        }
        $shippingAddressLine1 = $addressForShipping->getStreet();
        $shippingAddressAddition1 = $addressForShipping->getAdditionalAddressLine1();
        $shippingAddressAddition2 = $addressForShipping->getAdditionalAddressLine2();

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
                'city' => $billingAddress->getCity(),
                'country_code' => $billingAddress->getCountry()->getIso(),
                'zip_code' => $billingAddress->getZipCode(),
            ],
            'shipping_address' => [
                'address_line1' => $shippingAddressLine1,
                'address_line2' => $shippingAddressLine2,
                'city' => $addressForShipping->getCity(),
                'country_code' => $addressForShipping->getCountry()->getIso(),
                'zip_code' => $addressForShipping->getZipCode(),
            ],
            'lines' => $this->orderLinesService->getLines($order, $context)
        ];
    }

    private function buildBuyerPayload($order, string $addressLine1, ?string $addressLine2): array
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

    public function createLocalOrder($transaction, $orderUuid, $context) {
        $orderTransaction = $this->getOrderTransaction($transaction->getOrderTransactionId(), $context);
        $order = $orderTransaction->getOrder();
        $salesChannelId = $order->getSalesChannelId();
        $monduOrder = $this->monduClient->setSalesChannelId($salesChannelId)->getMonduOrder($orderUuid);

        if (!$monduOrder) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), 'Could not fetch Mondu Order.');
        }

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

    private function deleteStaleOrderData(string $orderId, string $activeReferenceId, Context $context): void
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $orderId));
            $criteria->addFilter(new EqualsFilter('successful', false));
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

    private function getOrderTransaction(string $transactionId, Context $context)
    {
        $criteria = new Criteria([$transactionId]);
        $criteria->addAssociation('order.orderCustomer.customer');
        $criteria->addAssociation('order.billingAddress.country');
        $criteria->addAssociation('order.deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.lineItems');
        $criteria->addAssociation('order.price.calculatedTaxes');
        $criteria->addAssociation('paymentMethod');

        return $this->orderTransactionRepository->search($criteria, $context)->first();
    }

    private function getOrder(string $orderId, Context $context)
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('orderCustomer.customer');
        $criteria->addAssociation('billingAddress.country');
        $criteria->addAssociation('deliveries.shippingOrderAddress.country');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('price.calculatedTaxes');

        return $this->orderRepository->search($criteria, $context)->first();
    }
}
