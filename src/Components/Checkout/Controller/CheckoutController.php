<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Controller;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Shopware\Core\Checkout\Cart\Order\OrderConversionContext;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @Route(path="/mondu-payment")
 * @RouteScope(scopes={"storefront"})
 */
class CheckoutController extends StorefrontController
{
    private MonduClient $monduClient;

    public function __construct(MonduClient $monduClient)
    {
        $this->monduClient = $monduClient;
    }
    /**
     * @Route(path="/token", name="mondu-payment.checkout.token", methods={"GET"}, defaults={"XmlHttpRequest"=true})
     * @param Request $request
     * @return JsonResponse
     */
    public function getToken(Request $request, SalesChannelContext $salesChannelContext, CartService $cartService, OrderConverter $orderConverter, NumberRangeValueGeneratorInterface $numberRangeValueGenerator): JsonResponse
    {
        $orderNumber = $numberRangeValueGenerator->getValue(
            'order',
            $salesChannelContext->getContext(),
            $salesChannelContext->getSalesChannel()->getId()
        );

        $cart = $cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);

        if ($salesChannelContext->getCurrency()->getIsoCode() !== 'EUR') {
            // handle
        }

        $order = $this->getOrderData($cart, $cart->getLineItems(), $salesChannelContext->getContext(), $salesChannelContext->getCustomer(), $orderNumber);

        $monduOrder = $this->monduClient->createOrder($order);

        return new JsonResponse([
            'token' => @$monduOrder['uuid']
        ]);
    }
    protected function getLineItems($collection, Context $context): array
    {
        $lineItems = [];
        /** @var \Shopware\Core\Checkout\Cart\LineItem\LineItem|OrderLineItemEntity $lineItem */
        foreach ($collection->getIterator() as $lineItem) {
            if ($lineItem->getType() !== \Shopware\Core\Checkout\Cart\LineItem\LineItem::PRODUCT_LINE_ITEM_TYPE) {
                // item is not a product (it is a voucher etc.).
                continue;
            }

            $unitNetPrice = ($lineItem->getPrice()->getUnitPrice() - ($lineItem->getPrice()->getCalculatedTaxes()->getAmount() / $lineItem->getQuantity())) * 100;
            $lineItems[] = [
                'external_reference_id' => $lineItem->getReferencedId(),
                'quantity' => $lineItem->getQuantity(),
                'title' => $lineItem->getLabel(),
                'net_price_cents' => round($unitNetPrice * $lineItem->getQuantity()),
                'net_price_per_item_cents' => round($unitNetPrice)
            ];
        }

        return $lineItems;
    }

    protected function getOrderData($cart, $collection, Context $context, $customer, $orderNumber)
    {
        $lineItems = $this->getLineItems($collection, $context);
        return [
            'currency' => 'EUR',
            'external_reference_id' => $orderNumber,
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
                    'shipping_price_cents' => $cart->getDeliveries()->getShippingCosts()->sum()->getTotalPrice() * 100,
                    'line_items' => $lineItems
                ]
            ]
        ];
    }
}
