<?php

namespace Mondu\MonduPayment\Services\OrderServices;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class OrderDiscountService extends AbstractOrderDiscountService
{
    public function getDecorated(): AbstractOrderDiscountService
    {
        throw new DecorationPatternException(self::class);
    }

    public function getOrderDiscountCents(OrderEntity $order, Context $context, ?callable $isDiscountCallback = null): int
    {
        $discountAmount = 0;

        foreach ($order->getLineItems() as $shopwareLineItem) {
            if (
                isset($isDiscountCallback) && !$isDiscountCallback($shopwareLineItem) ||
                !$this->orderUtilsService->isDiscount($shopwareLineItem)
            ) {
                continue;
            }

            if ($order->getTaxStatus() === CartPrice::TAX_STATE_GROSS) {
                $unitNetPrice = ($shopwareLineItem->getPrice()->getUnitPrice() - ($shopwareLineItem->getPrice()->getCalculatedTaxes()->getAmount() / $shopwareLineItem->getQuantity()));
            } else {
                $unitNetPrice = $shopwareLineItem->getPrice()->getUnitPrice();
            }

            $discountAmount += abs($unitNetPrice);
        }

        return $this->orderUtilsService->priceToCents($discountAmount);
    }
}
