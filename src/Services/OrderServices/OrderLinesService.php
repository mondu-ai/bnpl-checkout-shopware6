<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class OrderLinesService extends AbstractOrderLinesService
{
    public function getDecorated(): AbstractOrderLinesService
    {
        throw new DecorationPatternException(self::class);
    }

    public function getLines(OrderEntity $order, Context $context): array
    {
        $shipping = $this->getShippingPrice($order, $order->getTaxStatus());

        return [
            [
                'tax_cents' => (int) round($order->getPrice()->getCalculatedTaxes()->getAmount() * 100),
                'shipping_price_cents' => (int) round($shipping * 100),
                'discount_cents' => $this->orderDiscountService->getOrderDiscountCents($order, $context),
                'buyer_fee_cents' => $this->additionalCostsService->getAdditionalCostsCents($order, $context),
                'line_items' => $this->orderLineItemsService->getLineItems($order, $context)
            ]
        ];
    }

    protected function getShippingPrice(OrderEntity $order, string $taxStatus): float
    {
        if ($taxStatus === CartPrice::TAX_STATE_GROSS) {
            return $order->getShippingCosts()->getTotalPrice() - $order->getShippingCosts()->getCalculatedTaxes()->getAmount();
        }

        return $order->getShippingCosts()->getTotalPrice();
    }
}
