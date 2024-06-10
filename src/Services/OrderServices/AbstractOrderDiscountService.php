<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

abstract class AbstractOrderDiscountService
{
    abstract public function getDecorated(): AbstractOrderDiscountService;

    /**
     * Total Discount that is being sent to Mondu
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return int
     */
    abstract public function getOrderDiscountCents(OrderEntity $order, Context $context, ?callable $isDiscountCallback = null): int;
}
