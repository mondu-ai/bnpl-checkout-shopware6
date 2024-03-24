<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler;

use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;

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
        private readonly ConfigService $configService
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
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $paymentState = $request->query->getAlpha('payment');
        $context = $salesChannelContext->getContext();

        if ($paymentState === self::PAYMENT_STATE_SUCCESS) {
            $paymentOrderUuid = $request->query->get('order_uuid');

            $confirmResponseState = $this->monduClient->setSalesChannelId($salesChannelContext->getSalesChannelId())->confirmOrder($paymentOrderUuid);

            if (!$this->isOrderConfirmed($confirmResponseState)) {
                throw new CustomerCanceledAsyncPaymentException(
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
        } else {
            $this->transactionStateHandler->fail($transaction->getOrderTransaction()->getId(), $context);

            throw new CustomerCanceledAsyncPaymentException(
                $transactionId,
                'Canceled/declined payment in Mondu Checkout.'
            );
        }
    }

    private function createOrder(AsyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext): string
    {
        $orderData = $this->getOrderData($transaction, $salesChannelContext);

        $monduOrder = $this->monduClient->setSalesChannelId($salesChannelContext->getSalesChannelId())->createOrder($orderData);

        return $monduOrder['hosted_checkout_url'];
    }

    protected function getOrderData(AsyncPaymentTransactionStruct $transaction, SalesChannelContext $salesChannelContext)
    {
        $order = $transaction->getOrder();
        $returnUrl = $transaction->getReturnUrl();
        $orderTransaction = $transaction->getOrderTransaction();
        $context = $salesChannelContext->getContext();

        $lineItems = $this->getLineItems($order->getLineItems(), $context);;
        $discount = $this->getDiscount($order->getLineItems(), $context);

        if ($context->getTaxState() === CartPrice::TAX_STATE_GROSS) {
            $shipping = $order->getShippingCosts()->getTotalPrice() - $order->getShippingCosts()->getCalculatedTaxes()->getAmount();
        } else {
            $shipping = $order->getShippingCosts()->getTotalPrice();
        }

        $shippingAddress = $order->getDeliveries()->getShippingAddress()->first();
        $paymentMethod = MethodHelper::shortNameToMonduName($orderTransaction->getPaymentMethod()->getShortName());

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
                'is_registered' => !$order->getOrderCustomer()->getCustomer()->getGuest()
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
            'lines' => [
                [
                    'tax_cents' => round($order->getPrice()->getCalculatedTaxes()->getAmount() * 100),
                    'shipping_price_cents' => round($shipping * 100),
                    'discount_cents' => round($discount),
                    'line_items' => $lineItems
                ]
            ]
        ];
    }

    protected function getLineItems($collection, Context $context): array
    {
        $productIds = $collection->fmap(
            function ($lineItem) {
                return $lineItem->getProductId(); 
            }
        );

        $products = $this->productRepository->search(new Criteria($productIds), $context);

        $lineItems = [];
        
        foreach ($collection->getIterator() as $lineItem) {
            if ($lineItem->getType() !== 'product') {
                continue;
            }

            $product = $products->filter(function ($product) use ($lineItem) {
                return $product->getId() == $lineItem->getProductId();
            })->first();

            if ($context->getTaxState() === CartPrice::TAX_STATE_GROSS) {
                $unitNetPrice = ($lineItem->getPrice()->getUnitPrice() - ($lineItem->getPrice()->getCalculatedTaxes()->getAmount() / $lineItem->getQuantity())) * 100;
            } else {
                $unitNetPrice = $lineItem->getPrice()->getUnitPrice() * 100;
            }

            $lineItems[] = [
                'external_reference_id' => $lineItem->getReferencedId(),
                'product_id' => $product->getProductNumber(),
                'quantity' => $lineItem->getQuantity(),
                'title' => $lineItem->getLabel(),
                'net_price_cents' => round($unitNetPrice * $lineItem->getQuantity()),
                'net_price_per_item_cents' => round($unitNetPrice)
            ];
        }

        return $lineItems;
    }

    protected function getDiscount($collection, Context $context): float
    {
        $discountAmount = 0;
        /** @var LineItem|OrderLineItemEntity $lineItem */
        foreach ($collection->getIterator() as $lineItem) {
            $discountLineItemType = 'discount';

            if (defined( '\Shopware\Core\Checkout\Cart\LineItem\LineItem::DISCOUNT_LINE_ITEM'))
                $discountLineItemType = LineItem::DISCOUNT_LINE_ITEM;

            if ($lineItem->getType() !== LineItem::PROMOTION_LINE_ITEM_TYPE &&
                $lineItem->getType() !== $discountLineItemType) {
                continue;
            }

            if ($context->getTaxState() === CartPrice::TAX_STATE_GROSS) {
                $unitNetPrice = ($lineItem->getPrice()->getUnitPrice() - ($lineItem->getPrice()->getCalculatedTaxes()->getAmount() / $lineItem->getQuantity())) * 100;
            } else {
                $unitNetPrice = $lineItem->getPrice()->getUnitPrice() * 100;
            }
            $discountAmount += abs($unitNetPrice);
        }

        return $discountAmount;
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
                OrderDataEntity::FIELD_IS_SUCCESSFUL => true,
            ]
        ], $salesChannelContext->getContext());
    }

    protected function isOrderConfirmed($confirmResponseState)
    {
        return in_array($confirmResponseState, [self::RESPONSE_STATE_CONFIRMED, self::RESPONSE_STATE_PENDING]);
    }
}
