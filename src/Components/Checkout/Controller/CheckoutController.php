<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Controller;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Shopware\Core\Checkout\Cart\Order\OrderConversionContext;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Annotation\RouteScope;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @Route(path="/mondu-payment", defaults={"_routeScope"={"storefront"}})
 */
class CheckoutController extends StorefrontController
{
    private MonduClient $monduClient;
    private EntityRepository $productRepository;

    public function __construct(MonduClient $monduClient, EntityRepository $productRepository)
    {
        $this->monduClient = $monduClient;
        $this->productRepository = $productRepository;
    }
    /**
     * @Route(path="/token", name="frontend.mondu-payment.checkout.token", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     * @param Request $request
     * @return JsonResponse
     */
    public function getToken(Request $request, SalesChannelContext $salesChannelContext, CartService $cartService, OrderConverter $orderConverter, NumberRangeValueGeneratorInterface $numberRangeValueGenerator): JsonResponse
    {
        $paymentMethod = MethodHelper::monduPaymentMethodOrDefault($request->get('payment_method'));

        $orderNumber = uniqid('M_SW6_');

        $cart = $cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);

        $order = $this->getOrderData(
            $cart,
            $cart->getLineItems(),
            $salesChannelContext->getContext(),
            $salesChannelContext->getCustomer(),
            $orderNumber,
            $paymentMethod,
            $salesChannelContext->getCurrency()->getIsoCode()
        );

        $monduOrder = $this->monduClient->setSalesChannelId($salesChannelContext->getSalesChannelId())->createOrder($order);

        
        return new JsonResponse([
            'token' => isset($monduOrder['uuid']) ? $monduOrder['uuid'] : 'error'
        ]);
    }
    protected function getLineItems($collection, Context $context): array
    {
        $productIds = $collection->fmap(
            function (\Shopware\Core\Checkout\Cart\LineItem\LineItem $lineItem) {
                return $lineItem->getId(); 
            }
        );

        $products = $this->productRepository->search(new Criteria($productIds), $context);

        $lineItems = [];
        
        /** @var \Shopware\Core\Checkout\Cart\LineItem\LineItem|OrderLineItemEntity $lineItem */
        foreach ($collection->getIterator() as $lineItem) {
            if ($lineItem->getType() !== \Shopware\Core\Checkout\Cart\LineItem\LineItem::PRODUCT_LINE_ITEM_TYPE) {
                // item is not a product (it is a voucher etc.).
                continue;
            }

            $product = $products->filter(function ($product) use ($lineItem) {
                return $product->getId() == $lineItem->getId();
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
        /** @var \Shopware\Core\Checkout\Cart\LineItem\LineItem|OrderLineItemEntity $lineItem */
        foreach ($collection->getIterator() as $lineItem) {
            $discountLineItemType = 'discount';

            if (defined( '\Shopware\Core\Checkout\Cart\LineItem\LineItem::DISCOUNT_LINE_ITEM'))
                $discountLineItemType = \Shopware\Core\Checkout\Cart\LineItem\LineItem::DISCOUNT_LINE_ITEM;

            if ($lineItem->getType() !== \Shopware\Core\Checkout\Cart\LineItem\LineItem::PROMOTION_LINE_ITEM_TYPE &&
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

    protected function getOrderData($cart, $collection, Context $context, $customer, $orderNumber, $paymentMethod, $currency)
    {
        $lineItems = $this->getLineItems($collection, $context);
        $shipping = $cart->getDeliveries()->getShippingCosts()->sum()->getTotalPrice();

        $discount = $this->getDiscount($collection, $context);

        if ($context->getTaxState() === CartPrice::TAX_STATE_GROSS) {
            $shipping = $cart->getDeliveries()->getShippingCosts()->sum()->getTotalPrice() - $cart->getDeliveries()->getShippingCosts()->sum()->getCalculatedTaxes()->getAmount();
        } else {
            $shipping = $cart->getDeliveries()->getShippingCosts()->sum()->getTotalPrice();
        }

        return [
            'currency' => $currency,
            'payment_method' => $paymentMethod,
            'external_reference_id' => $orderNumber,
            'gross_amount_cents' => round($cart->getPrice()->getTotalPrice() * 100),
            'buyer' => [
                'email' => $customer->getEmail(),
                'first_name' => $customer->getFirstname(),
                'last_name' => $customer-> getLastName(),
                'company_name' => $customer->getCompany() ?? $customer->getDefaultBillingAddress()->getCompany(),
                'phone' => $customer->getDefaultBillingAddress()->getPhoneNumber(),
                'address_line1' => $customer->getDefaultBillingAddress()->getStreet(),
                'zip_code' => $customer->getDefaultBillingAddress()->getZipCode(),
                'is_registered' => !$customer->getGuest()
            ],
            'billing_address' => [
                'address_line1' => $customer->getDefaultBillingAddress()->getStreet(),
                'city' => $customer->getDefaultBillingAddress()->getCity(),
                'country_code' => $customer->getDefaultBillingAddress()->getCountry()->getIso(),
                'zip_code' => $customer->getDefaultBillingAddress()->getZipCode(),
            ],
            'shipping_address' => [
                'address_line1' => $customer->getDefaultShippingAddress()->getStreet(),
                'city' => $customer->getDefaultShippingAddress()->getCity(),
                'country_code' => $customer->getDefaultShippingAddress()->getCountry()->getIso(),
                'zip_code' => $customer->getDefaultShippingAddress()->getZipCode(),
            ],
            'lines' => [
                [
                    'tax_cents' => round($cart->getPrice()->getCalculatedTaxes()->getAmount() * 100),
                    'shipping_price_cents' => round($shipping * 100),
                    'discount_cents' => round($discount),
                    'line_items' => $lineItems
                ]
            ]
        ];
    }
}
