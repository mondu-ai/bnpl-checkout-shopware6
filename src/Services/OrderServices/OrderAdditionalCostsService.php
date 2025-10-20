<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class OrderAdditionalCostsService extends AbstractOrderAdditionalCostsService
{
    public function getDecorated(): AbstractOrderAdditionalCostsService
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * Returns additional costs associated with order
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return int
     */
    public function getAdditionalCostsCents(OrderEntity $order, Context $context): int
    {
        return 0;
    }
}
