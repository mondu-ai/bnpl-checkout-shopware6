<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Struct\Struct;

class OrderUtilsService extends AbstractOrderUtilsService
{
    public function isLineItem(mixed $lineItem): bool
    {
        return $lineItem instanceof OrderLineItemEntity
            && $lineItem->getPrice()->getTotalPrice() >= 0
            && in_array($lineItem->getType(), self::LINE_ITEM_TYPES);
    }

    public function isDiscount(mixed $lineItem): bool
    {
        return $lineItem instanceof OrderLineItemEntity
            && $lineItem->getPrice()->getTotalPrice() < 0
            && in_array($lineItem->getType(), self::DISCOUNT_TYPES);
    }

    public function priceToCents(float $price): int
    {
        return (int) round($price * 100);
    }

    public function getLineItemNetPrice(OrderLineItemEntity $shopwareLineItem, string $taxStatus): float
    {
        $calculatedPrice = $shopwareLineItem->getPrice();
        $quantity = $shopwareLineItem->getQuantity();

        if ($taxStatus !== CartPrice::TAX_STATE_GROSS) {
            return $calculatedPrice->getUnitPrice();
        }

        return $calculatedPrice->getUnitPrice() - ($calculatedPrice->getCalculatedTaxes()->getAmount() / $quantity);
    }

    public function getOrderCurrency(OrderEntity $order): string
    {
        return $order->getCurrency()->getIsoCode();
    }

    public function getShippingPriceCents(OrderEntity $order): int
    {
        if ($order->getTaxStatus() === CartPrice::TAX_STATE_GROSS) {
            $shipping = $order->getShippingCosts()->getTotalPrice() - $order->getShippingCosts()->getCalculatedTaxes()->getAmount();
            return $this->priceToCents($shipping);
        }

        return $this->priceToCents($order->getShippingCosts()->getTotalPrice());
    }

    public function getMonduDataFromOrder(OrderEntity $order): ?Struct
    {
        return $order->getExtension(OrderExtension::EXTENSION_NAME);
    }
}
