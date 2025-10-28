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

            // Log payment state for debugging
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

            try {
                if (
                    $orderTransactionState == self::ORDER_TRANSACTION_STATE_PAID &&
                    $confirmResponseState == self::RESPONSE_STATE_PENDING
                ) {
                    $this->transactionStateHandler->processUnconfirmed($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                } else if ($orderTransactionState == self::ORDER_TRANSACTION_STATE_AUTHORIZED) {
                    $this->transactionStateHandler->authorize($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                } else {
                    $this->transactionStateHandler->paid($transaction->getOrderTransaction()->getId(), $salesChannelContext->getContext());
                }
            } catch (\Throwable $e) {
                // Catch "cannot be edited" errors if order was cancelled by webhook during finalize
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
                    // Don't re-throw - order was confirmed in Mondu, webhook will handle the rest
                } else {
                    // Re-throw unexpected errors
                    throw $e;
                }
            }
        } else {
            // DECLINED or CANCELLED: transition transaction state accordingly
            // Declined → fail (Fehlgeschlagen), Cancelled → cancel (Abgebrochen)
            // Order state handling depends on autoTransitionOrderState setting
            try {
                if ($paymentState === 'declined') {
                    $this->transactionStateHandler->fail($transaction->getOrderTransaction()->getId(), $context);
                    
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Transaction state set to FAIL for declined payment', [
                            'order_id' => $transaction->getOrder()->getId(),
                            'order_number' => $transaction->getOrder()->getOrderNumber(),
                            'transaction_id' => $transaction->getOrderTransaction()->getId()
                        ]);
                    }
                } else {
                    // cancelled
                    $this->transactionStateHandler->cancel($transaction->getOrderTransaction()->getId(), $context);
                    
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Transaction state set to CANCEL for cancelled payment', [
                            'order_id' => $transaction->getOrder()->getId(),
                            'order_number' => $transaction->getOrder()->getOrderNumber(),
                            'transaction_id' => $transaction->getOrderTransaction()->getId()
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                // Catch "cannot be edited" errors if order was already cancelled by webhook
                if (strpos($e->getMessage(), 'cannot be edited') !== false || 
                    strpos($e->getMessage(), 'was cancelled') !== false) {
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Order was already cancelled by webhook, finalize completed without further action', [
                            'order_id' => $transaction->getOrder()->getId(),
                            'order_number' => $transaction->getOrder()->getOrderNumber(),
                            'error' => $e->getMessage()
                        ]);
                    }
                    // Return silently - order already cancelled by webhook, no need to show error to user
                    return;
                } else {
                    // Re-throw unexpected errors
                    throw $e;
                }
            }

            // Dispatch Flow Builder event for cancelled/declined payment
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
            }

            throw PaymentException::customerCanceled(
                $transactionId,
                'Canceled/declined payment in Mondu Checkout.'
            );
        }
        } catch (\Throwable $globalEx) {
            // Cancelled/declined payments are expected user actions, not system errors
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
                // Log as error only for unexpected exceptions
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
            
            // Re-throw all exceptions
            throw $globalEx;
        }
    }

    private function createOrder(AsyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext): string
    {
        $orderData = $this->getOrderData($transaction, $salesChannelContext);
        $monduOrder = $this->monduClient->setSalesChannelId($salesChannelContext->getSalesChannelId())->createOrder($orderData);

        // Save external_reference_id immediately to handle webhooks before finalize
        $this->saveEarlyOrderData($transaction, $monduOrder, $salesChannelContext);

        return $monduOrder['hosted_checkout_url'];
    }

    /**
     * Save minimal order data immediately after Mondu order creation
     * This ensures webhooks (especially declined/cancelled) can find the order
     * even if customer never returns to finalize the payment
     */
    private function saveEarlyOrderData($transaction, $monduOrder, $salesChannelContext): void
    {
        try {
            $order = $transaction->getOrder();
            
            $this->orderDataRepository->upsert([
                [
                    OrderDataEntity::FIELD_ORDER_ID => $order->getId(),
                    OrderDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                    OrderDataEntity::FIELD_REFERENCE_ID => $monduOrder['uuid'],
                    OrderDataEntity::FIELD_EXTERNAL_REFERENCE_ID => $monduOrder['external_reference_id'] ?? null,
                    OrderDataEntity::FIELD_ORDER_STATE => $monduOrder['state'] ?? 'pending',
                    OrderDataEntity::FIELD_VIBAN => null, // Will be updated in finalize
                    OrderDataEntity::FIELD_DURATION => 0, // Will be updated in finalize
                    OrderDataEntity::FIELD_IS_SUCCESSFUL => false, // Will be updated in finalize
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
            // Log but don't fail the payment process
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

        // Log external_reference_id if Extended logs enabled
        if ($this->configService->setSalesChannelId($salesChannelContext->getSalesChannelId())->isExtendedLogsEnabled()) {
            $this->logger->info('mondu.INFO: Generated external_reference_id for Mondu order', [
                'external_reference_id' => $externalReferenceId,
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'payment_method' => $paymentMethod,
                'total_amount' => $order->getPrice()->getTotalPrice(),
            ]);
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
            'buyer' => [
                'email' => $order->getOrderCustomer()->getEmail(),
                'first_name' => $order->getOrderCustomer()->getFirstname(),
                'last_name' => $order->getOrderCustomer()-> getLastName(),
                'company_name' => $order->getOrderCustomer()->getCompany(),
                'phone' => $order->getBillingAddress()->getPhoneNumber(),
                'address_line1' => $order->getBillingAddress()->getStreet(),
                'zip_code' => $order->getBillingAddress()->getZipCode(),
                'is_registered' => !$order->getOrderCustomer()->getCustomer()->getGuest(),
                'external_reference_id' => $order->getOrderCustomer()->getCustomer()->getCustomerNumber(),
                'account_created_at' => $order->getOrderCustomer()->getCustomer()->getCreatedAt(),
                'account_updated_at' => $order->getOrderCustomer()->getCustomer()->getUpdatedAt(),

            ],
            'billing_address' => [
                'address_line1' => $order->getBillingAddress()->getStreet(),
                'city' => $order->getBillingAddress()->getCity(),
                'country_code' => $order->getBillingAddress()->getCountry()->getIso(),
                'zip_code' => $order->getBillingAddress()->getZipCode(),
            ],
            'shipping_address' => [
                'address_line1' => $shippingAddress->getStreet(),
                'city' => $shippingAddress->getCity(),
                'country_code' => $shippingAddress->getCountry()->getIso(),
                'zip_code' => $shippingAddress->getZipCode(),
            ],
            'lines' => $this->orderLinesService->getLines($order, $salesChannelContext->getContext())
        ];
    }

    public function createLocalOrder($transaction, $orderUuid, $salesChannelContext) {
        $order = $transaction->getOrder();
        $monduOrder = $this->monduClient->setSalesChannelId($salesChannelContext->getSalesChannelId())->getMonduOrder($orderUuid);
        
        if (!$monduOrder) {
            throw new AsyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'Could not fetch Mondu Order.');
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
        ], $salesChannelContext->getContext());
    }

    protected function isOrderConfirmed($confirmResponseState)
    {
        return in_array($confirmResponseState, [self::RESPONSE_STATE_CONFIRMED, self::RESPONSE_STATE_PENDING]);
    }
}

