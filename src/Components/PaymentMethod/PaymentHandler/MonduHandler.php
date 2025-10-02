<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler;

use Shopware\Core\Checkout\Payment\PaymentException;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
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
        private readonly EntityRepository $orderTransactionRepository
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
            error_log(
                'An error occurred during the communication with external payment gateway: ' . $e->getMessage()
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

        if ($paymentState === self::PAYMENT_STATE_SUCCESS) {
            $paymentOrderUuid = $request->query->get('order_uuid');

            // Get sales channel ID from the order
            $orderTransaction = $this->getOrderTransaction($transactionId, $context);
            $order = $orderTransaction->getOrder();
            $salesChannelId = $order->getSalesChannelId();

            $confirmResponseState = $this->monduClient->setSalesChannelId($salesChannelId)->confirmOrder(
                $paymentOrderUuid,
                ['external_reference_id' => $order->getOrderNumber()]
            );

            if (!$this->isOrderConfirmed($confirmResponseState)) {
                error_log(
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

            if (
                $orderTransactionState == self::ORDER_TRANSACTION_STATE_PAID &&
                $confirmResponseState == self::RESPONSE_STATE_PENDING
            ) {
                $this->transactionStateHandler->process($transactionId, $context);
            } else if ($orderTransactionState == self::ORDER_TRANSACTION_STATE_AUTHORIZED) {
                $this->transactionStateHandler->authorize($transactionId, $context);
            } else {
                $this->transactionStateHandler->paid($transactionId, $context);
            }
        } else {
            $this->safeTransitionToFailed($transactionId, $context);

            error_log(
                $transactionId,
                'Canceled/declined payment in Mondu Checkout.'
            );
        }
    }

    private function createOrder(PaymentTransactionStruct $transaction, Context $context): string
    {
        $orderData = $this->getOrderData($transaction, $context);
        $orderTransaction = $this->getOrderTransaction($transaction->getOrderTransactionId(), $context);
        $order = $orderTransaction->getOrder();
        $salesChannelId = $order->getSalesChannelId();
        $monduOrder = $this->monduClient->setSalesChannelId($salesChannelId)->createOrder($orderData);

        return $monduOrder['hosted_checkout_url'];
    }

    protected function getOrderData(PaymentTransactionStruct $transaction, Context $context)
    {
        $orderTransaction = $this->getOrderTransaction($transaction->getOrderTransactionId(), $context);
        $order = $orderTransaction->getOrder();
        $returnUrl = $transaction->getReturnUrl();

        $shippingAddress = $order->getDeliveries()->getShippingAddress()->first();
        $paymentMethod = MethodHelper::shortNameToMonduName($orderTransaction->getPaymentMethod()->getShortName());

        // Use billing address as fallback if shipping address is not available
        $addressForShipping = $shippingAddress ?? $order->getBillingAddress();

        return [
            'currency' => $order->getCurrency()->getIsoCode(),
            'state_flow' => 'authorization_flow',
            'payment_method' => $paymentMethod,
            'success_url' => $returnUrl . '&payment=success',
            'cancel_url' => $returnUrl . '&payment=cancelled',
            'declined_url' => $returnUrl . '&payment=declined',
            'external_reference_id' => uniqid('M_SW6_'),
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
                'address_line1' => $addressForShipping->getStreet(),
                'city' => $addressForShipping->getCity(),
                'country_code' => $addressForShipping->getCountry()->getIso(),
                'zip_code' => $addressForShipping->getZipCode(),
            ],
            'lines' => $this->orderLinesService->getLines($order, $context)
        ];
    }

    public function createLocalOrder($transaction, $orderUuid, $context) {
        $orderTransaction = $this->getOrderTransaction($transaction->getOrderTransactionId(), $context);
        $order = $orderTransaction->getOrder();
        $salesChannelId = $order->getSalesChannelId();
        $monduOrder = $this->monduClient->setSalesChannelId($salesChannelId)->getMonduOrder($orderUuid);
        
        if (!$monduOrder) {
            error_log('Could not fetch Mondu Order.');
        }

        $this->orderDataRepository->upsert([
            [
                OrderDataEntity::FIELD_ORDER_ID => $order->getId(),
                OrderDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                OrderDataEntity::FIELD_REFERENCE_ID => $monduOrder['uuid'],
                OrderDataEntity::FIELD_ORDER_STATE => $monduOrder['state'],
                OrderDataEntity::FIELD_VIBAN => $monduOrder['bank_account']['iban'],
                OrderDataEntity::FIELD_DURATION => $monduOrder['authorized_net_term'],
                OrderDataEntity::FIELD_IS_SUCCESSFUL => true,
            ]
        ], $context);
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
        $criteria->addAssociation('order.deliveries.shippingAddress.country');
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
        $criteria->addAssociation('deliveries.shippingAddress.country');
        $criteria->addAssociation('currency');
        $criteria->addAssociation('lineItems');
        $criteria->addAssociation('price.calculatedTaxes');

        return $this->orderRepository->search($criteria, $context)->first();
    }

    /**
     * Safely transition transaction to failed state, handling different current states
     */
    private function safeTransitionToFailed(string $transactionId, Context $context): void
    {
        try {
            $this->transactionStateHandler->fail($transactionId, $context);
        } catch (\Exception $e) {
            try {
                $this->transactionStateHandler->reopen($transactionId, $context);
                $this->transactionStateHandler->fail($transactionId, $context);
            } catch (\Exception $e2) {
                error_log(
                    'MONDU DEBUG: All transition attempts failed. Original error: ' .
                    $e->getMessage() .
                    ', Reopen error: ' .
                    $e2->getMessage()
                );
            }
        }
        
        error_log('MONDU DEBUG: safeTransitionToFailed() completed');
    }
}
