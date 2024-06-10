<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

abstract class AbstractOrderLinesService
{
    public function __construct(
        protected readonly AbstractOrderAdditionalCostsService $additionalCostsService,
        protected readonly AbstractOrderLineItemsService       $orderLineItemsService,
        protected readonly AbstractOrderDiscountService        $orderDiscountService
    ) {}

    abstract public function getDecorated(): AbstractOrderLinesService;

    /**
     * Lines that are being sent to Mondu
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return array
     */
    abstract public function getLines(OrderEntity $order, Context $context): array;
}
