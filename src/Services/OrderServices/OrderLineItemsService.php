<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class OrderLineItemsService extends AbstractOrderLineItemsService
{
    public function getDecorated(): AbstractOrderLineItemsService
    {
        throw new DecorationPatternException(self::class);
    }

    public function getLineItems(OrderEntity $order, Context $context, ?callable $isLineItemCallback = null): array
    {
        $shopwareLineItems = $order->getLineItems();
        $lineItems = [];

        if ($shopwareLineItems === null) {
            return $lineItems;
        }

        foreach ($shopwareLineItems as $shopwareLineItem) {
            if (
                isset($isLineItemCallback) && !$isLineItemCallback($shopwareLineItem) ||
                !$this->isLineItem($shopwareLineItem)
            ) {
                continue;
            }

            $quantity = $shopwareLineItem->getQuantity();
            $unitNetPrice = $this->getUnitNetPrice($shopwareLineItem, $order->getTaxStatus());
            $productNumber = $shopwareLineItem->getPayload()['productNumber'] ?? null;
            $lineItems[] = [
                'external_reference_id' => $shopwareLineItem->getReferencedId() ?? $shopwareLineItem->getUniqueIdentifier(),
                'product_id' => $productNumber ?? $shopwareLineItem->getUniqueIdentifier(),
                'quantity' => $quantity,
                'title' => $shopwareLineItem->getLabel(),
                'net_price_per_item_cents' => $this->toCents($unitNetPrice),
                'net_price_cents' => $this->toCents($unitNetPrice * $quantity),
            ];
        }

        return $lineItems;
    }

    /**
     * Checks whether line item is a product ( not a discount )
     *
     * @param mixed $lineItem
     * @return bool
     */
    protected function isLineItem(mixed $lineItem): bool
    {
        return $lineItem instanceof OrderLineItemEntity
            && $lineItem->getPrice()->getTotalPrice() >= 0
            && in_array($lineItem->getType(), [
                LineItem::PRODUCT_LINE_ITEM_TYPE,
                LineItem::CONTAINER_LINE_ITEM,
                LineItem::CUSTOM_LINE_ITEM_TYPE
            ]);
    }

    protected function getUnitNetPrice(OrderLineItemEntity $shopwareLineItem, string $taxStatus): float
    {
        $calculatedPrice = $shopwareLineItem->getPrice();
        $quantity = $shopwareLineItem->getQuantity();

        if ($taxStatus !== CartPrice::TAX_STATE_GROSS) {
            return $calculatedPrice->getUnitPrice();
        }

        return $calculatedPrice->getUnitPrice() - ($calculatedPrice->getCalculatedTaxes()->getAmount() / $quantity);
    }

    protected function toCents($price): int
    {
        return (int) round($price * 100);
    }
}