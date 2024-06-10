<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

abstract class AbstractOrderAdditionalCostsService
{
    abstract public function getDecorated(): AbstractOrderAdditionalCostsService;

    /**
     * Additional costs associated with order in cents
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return int
     */
    abstract public function getAdditionalCostsCents(OrderEntity $order, Context $context): int;
}