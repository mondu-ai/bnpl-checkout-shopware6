<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\InvoiceServices;

use Mondu\MonduPayment\Components\Order\Util\DocumentUrlHelper;
use Mondu\MonduPayment\Services\OrderServices\AbstractOrderAdditionalCostsService;
use Mondu\MonduPayment\Services\OrderServices\AbstractOrderDiscountService;
use Mondu\MonduPayment\Services\OrderServices\AbstractOrderLineItemsService;
use Mondu\MonduPayment\Services\OrderServices\AbstractOrderUtilsService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

abstract class AbstractInvoiceDataService
{
    public function __construct(
        protected readonly AbstractOrderUtilsService $orderUtilsService,
        protected readonly AbstractOrderDiscountService $orderDiscountService,
        protected readonly AbstractOrderLineItemsService $orderLineItemsService,
        protected readonly DocumentUrlHelper $documentUrlHelper
    ) {}

    abstract public function getDecorated(): AbstractInvoiceDataService;

    /**
     * Get invoice data that is being sent to Mondu API
     *
     * @param OrderEntity $order
     * @param Context $context
     * @return array
     */
    abstract public function getInvoiceData(OrderEntity $order, Context $context): array;
}
