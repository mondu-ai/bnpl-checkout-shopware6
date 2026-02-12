<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\OrderServices;

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
            if (method_exists($shopwareLineItem, 'getParentId') && $shopwareLineItem->getParentId() !== null) {
                continue;
            }

            if (
                isset($isLineItemCallback) && !$isLineItemCallback($shopwareLineItem) ||
                !$this->orderUtilsService->isLineItem($shopwareLineItem)
            ) {
                continue;
            }

            $lineItems[] = $forInvoice
                ? $this->getLineItemForInvoice($shopwareLineItem, $order)
                : $this->getLineItemForOrder($shopwareLineItem, $order);
        }

        if (empty($lineItems)) {
            $hasChildren = false;
            foreach ($shopwareLineItems as $shopwareLineItem) {
                if (method_exists($shopwareLineItem, 'getParentId') && $shopwareLineItem->getParentId() !== null) {
                    $hasChildren = true;
                    break;
                }
            }
            if ($hasChildren) {
                $lineItems[] = $forInvoice
                    ? $this->getAggregatedBundleLineForInvoice($order)
                    : $this->getAggregatedBundleLineForOrder($order);
            }
        }

        return $lineItems;
    }

    protected function getAggregatedBundleLineForOrder(OrderEntity $order): array
    {
        $orderPrice = $order->getPrice();
        $totalPrice = $orderPrice->getTotalPrice();
        $taxStatus = $order->getTaxStatus();
        $orderNet = $taxStatus === CartPrice::TAX_STATE_GROSS
            ? $totalPrice - $orderPrice->getCalculatedTaxes()->getAmount()
            : $totalPrice;
        $shippingNetCents = $this->orderUtilsService->getShippingPriceCents($order);
        $productsNet = $orderNet - ($shippingNetCents / 100.0);
        $netCents = $this->orderUtilsService->priceToCents(max(0, $productsNet));

        $parentLineItem = $this->getBundleParentLineItem($order);
        $title = 'Bundle';
        $productId = 'bundle-' . $order->getOrderNumber();
        $externalReferenceId = $order->getId();
        if ($parentLineItem !== null) {
            $label = $parentLineItem->getLabel();
            if ($label !== null && $label !== '') {
                $title = $label;
            }
            $payload = $parentLineItem->getPayload();
            $productId = $payload['productNumber'] ?? $parentLineItem->getUniqueIdentifier();
            $externalReferenceId = $parentLineItem->getReferencedId() ?? $parentLineItem->getUniqueIdentifier();
        }

        return [
            'external_reference_id' => $externalReferenceId,
            'quantity' => 1,
            'product_id' => $productId,
            'title' => $title,
            'net_price_per_item_cents' => $netCents,
            'net_price_cents' => $netCents,
        ];
    }

    private function getBundleParentLineItem(OrderEntity $order): ?OrderLineItemEntity
    {
        $shopwareLineItems = $order->getLineItems();
        if ($shopwareLineItems === null) {
            return null;
        }
        $parentId = null;
        foreach ($shopwareLineItems as $shopwareLineItem) {
            if (method_exists($shopwareLineItem, 'getParentId') && $shopwareLineItem->getParentId() !== null
                && $this->orderUtilsService->isLineItem($shopwareLineItem)) {
                $parentId = $shopwareLineItem->getParentId();
                break;
            }
        }
        if ($parentId === null) {
            return null;
        }
        foreach ($shopwareLineItems as $shopwareLineItem) {
            if ($shopwareLineItem->getId() === $parentId) {
                return $shopwareLineItem;
            }
        }
        return null;
    }

    protected function getAggregatedBundleLineForInvoice(OrderEntity $order): array
    {
        $parentLineItem = $this->getBundleParentLineItem($order);
        $externalReferenceId = $parentLineItem !== null
            ? ($parentLineItem->getReferencedId() ?? $parentLineItem->getUniqueIdentifier())
            : $order->getId();
        return [
            'external_reference_id' => $externalReferenceId,
            'quantity' => 1,
        ];
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
