<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

abstract class AbstractOrderLineItemsService
{
    abstract public function getDecorated(): AbstractOrderLineItemsService;

    /**
     * Line items that are being sent to Mondu
     *
     * @param OrderEntity $order
     * @param Context $context
     * @param callable|null $isLineItemCallback
     * @return array
     */
    abstract public function getLineItems(OrderEntity $order, Context $context, ?callable $isLineItemCallback = null): array;
}
