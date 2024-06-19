<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

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

    public function getLineItems(
        OrderEntity $order,
        Context $context,
        bool $forInvoice = false,
        ?callable $isLineItemCallback = null
    ): array
    {
        $shopwareLineItems = $order->getLineItems();
        $lineItems = [];

        if ($shopwareLineItems === null) {
            return $lineItems;
        }

        foreach ($shopwareLineItems as $shopwareLineItem) {
            if (
                isset($isLineItemCallback) && !$isLineItemCallback($shopwareLineItem) ||
                !$this->orderUtilsService->isLineItem($shopwareLineItem)
            ) {
                continue;
            }


            $lineItems[] = $forInvoice ? $this->getLineItemForInvoice($shopwareLineItem, $order) :
                $this->getLineItemForOrder($shopwareLineItem, $order);
        }

        return $lineItems;
    }

    protected function getLineItemForOrder(OrderLineItemEntity $shopwareLineItem, OrderEntity $order): array
    {
        return $this->getLineItemData($shopwareLineItem, $order->getTaxStatus());
    }

    protected function getLineItemForInvoice(OrderLineItemEntity $shopwareLineItem, OrderEntity $order): array
    {
        $data = $this->getLineItemData($shopwareLineItem, $order->getTaxStatus());

        return [
            'external_reference_id' => $data['external_reference_id'],
            'quantity' => $data['quantity']
        ];
    }

    protected function getLineItemData(OrderLineItemEntity $shopwareLineItem, string $taxStatus): array
    {
        $quantity = $shopwareLineItem->getQuantity();
        $unitNetPrice = $this->orderUtilsService->getLineItemNetPrice($shopwareLineItem, $taxStatus);

        $productNumber = $shopwareLineItem->getPayload()['productNumber'] ?? null;

        return [
            'external_reference_id' => $shopwareLineItem->getReferencedId() ?? $shopwareLineItem->getUniqueIdentifier(),
            'quantity' => $quantity,
            'product_id' => $productNumber ?? $shopwareLineItem->getUniqueIdentifier(),
            'title' => $shopwareLineItem->getLabel(),
            'net_price_per_item_cents' => $this->orderUtilsService->priceToCents($unitNetPrice),
            'net_price_cents' => $this->orderUtilsService->priceToCents($unitNetPrice * $quantity),
        ];
    }
}
