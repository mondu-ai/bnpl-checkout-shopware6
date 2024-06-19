<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;

abstract class AbstractOrderUtilsService
{
    public const LINE_ITEM_TYPES = [
        LineItem::PRODUCT_LINE_ITEM_TYPE,
        LineItem::CONTAINER_LINE_ITEM,
        LineItem::CUSTOM_LINE_ITEM_TYPE
    ];

    public const DISCOUNT_TYPES = [
        LineItem::DISCOUNT_LINE_ITEM,
        LineItem::CREDIT_LINE_ITEM_TYPE,
        LineItem::PROMOTION_LINE_ITEM_TYPE,
        LineItem::CUSTOM_LINE_ITEM_TYPE
    ];

    /**
     * Checks whether line item is a product ( not a discount )
     *
     * @param mixed $lineItem
     * @return bool
     */
    abstract public function isLineItem(mixed $lineItem): bool;

    /**
     * Checks whether the line item is Discount ( not product )
     *
     * @param mixed $lineItem
     * @return bool
     */
    abstract public function isDiscount(mixed $lineItem): bool;

    /**
     * Converts shopware float price to cents
     *
     * @param float $price
     * @return int
     */
    abstract public function priceToCents(float $price): int;

    /**
     * Returns net price of the product ( regardless of gross/net shopware configuration )
     *
     * @param OrderLineItemEntity $shopwareLineItem
     * @param string $taxStatus
     * @return float
     */
    abstract public function getLineItemNetPrice(OrderLineItemEntity $shopwareLineItem, string $taxStatus): float;

    /**
     * Returns currency of the order that is being sent to Mondu API
     *
     * @param OrderEntity $order
     * @return string
     */
    abstract public function getOrderCurrency(OrderEntity $order): string;

    /**
     * Returns shipping price
     *
     * @param OrderEntity $order
     * @return int
     */
    abstract public function getShippingPriceCents(OrderEntity $order): int;

    /**
     * Get data that we store for a particular shopware order
     *
     * @param OrderEntity $order
     * @return Struct|null
     */
    abstract public function getMonduDataFromOrder(OrderEntity $order): ?Struct;
}
