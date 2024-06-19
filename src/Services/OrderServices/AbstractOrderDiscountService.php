<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

abstract class AbstractOrderDiscountService
{
    public function __construct(
        protected readonly AbstractOrderUtilsService $orderUtilsService
    ) {}

    abstract public function getDecorated(): AbstractOrderDiscountService;

    /**
     * Total Discount that is being sent to Mondu
     *
     * @param OrderEntity $order
     * @param Context $context
     * @param callable|null $isDiscountCallback
     * @return int
     */
    abstract public function getOrderDiscountCents(OrderEntity $order, Context $context, ?callable $isDiscountCallback = null): int;
}
