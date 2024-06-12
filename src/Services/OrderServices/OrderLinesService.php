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
        return [
            [
                'tax_cents' => $this->orderUtilsService->priceToCents($order->getPrice()->getCalculatedTaxes()->getAmount()),
                'shipping_price_cents' => $this->orderUtilsService->getShippingPriceCents($order),
                'discount_cents' => $this->orderDiscountService->getOrderDiscountCents($order, $context),
                'buyer_fee_cents' => $this->additionalCostsService->getAdditionalCostsCents($order, $context),
                'line_items' => $this->orderLineItemsService->getLineItems($order, $context)
            ]
        ];
    }
}
