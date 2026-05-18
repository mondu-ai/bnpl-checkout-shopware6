<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\InvoiceServices;

use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class InvoiceDataService extends AbstractInvoiceDataService
{
    private const STORNO_TYPES = [
        'storno',
        'cancellation_invoice',
        'zugferd_cancellation_invoice',
        'zugferd_embedded_cancellation_invoice',
    ];

    public function getDecorated(): AbstractInvoiceDataService
    {
        throw new DecorationPatternException(self::class);
    }

    public function getInvoiceData(OrderEntity $order, Context $context): array
    {
        [ $invoiceNumber, $invoiceUrl ] = $this->getInvoiceNumberAndUrl($order, $context);

        if ($invoiceNumber === null || $invoiceNumber === '') {
            throw new \RuntimeException('Invoice number is required but not found. Please create an invoice document for this order first.');
        }

        $cancelledCreditAdjustment = $this->getAdjustmentForCancelledCreditNotes($order);

        $isActiveCreditItem = function ($lineItem) use ($cancelledCreditAdjustment) {
            if ($lineItem->getType() !== LineItem::CREDIT_LINE_ITEM_TYPE) {
                return true;
            }
            if ($cancelledCreditAdjustment['cutoff'] !== null && $lineItem->getCreatedAt() <= $cancelledCreditAdjustment['cutoff']) {
                return false;
            }
            return true;
        };

        return [
            'currency' => $this->orderUtilsService->getOrderCurrency($order),
            'external_reference_id' => (string) $invoiceNumber,
            'invoice_url' => $invoiceUrl,
            'gross_amount_cents' => $this->orderUtilsService->priceToCents($order->getPrice()->getTotalPrice()) + $cancelledCreditAdjustment['gross_amount_cents'],
            'discount_cents' => $this->orderDiscountService->getOrderDiscountCents($order, $context, $isActiveCreditItem),
            'shipping_price_cents' => $this->orderUtilsService->getShippingPriceCents($order),
            'line_items' => $this->orderLineItemsService->getLineItems($order, $context, true)
        ];
    }

    protected function getInvoiceNumberAndUrl(OrderEntity $order, Context $context): array
    {
        $monduData = $this->orderUtilsService->getMonduDataFromOrder($order);

        $invoiceNumber = $monduData->getExternalInvoiceNumber();
        $invoiceUrl = $monduData->getExternalInvoiceUrl();

        $attachedDocument = null;
        if ($context->hasExtension('mail-attachments')) {
            $mailAttachments = $context->getExtension('mail-attachments');
            $documentIds = $mailAttachments->getDocumentIds();
            if (!empty($documentIds)) {
                $attachedDocument = $documentIds[0];
            }
        }

        $cancelledByStornoIds = $this->getCancelledByStornoIds($order);

        if ($order->getDocuments()) {
            foreach ($order->getDocuments() as $document) {
                if (($attachedDocument && $document->getId() == $attachedDocument) || !$attachedDocument) {
                    if (
                        ($document->getDocumentType()->getTechnicalName() === 'invoice' ||
                         $document->getDocumentType()->getTechnicalName() === 'zugferd_embedded_invoice') &&
                        !in_array($document->getId(), $cancelledByStornoIds)
                    ) {
                        $config = $document->getConfig();
                        $invoiceNumber = $config['custom']['invoiceNumber'] ?? null;
                        $invoiceUrl = $this->documentUrlHelper->generateRouteForDocument($document);

                        if ($attachedDocument && $document->getId() == $attachedDocument) {
                            break;
                        }
                    }
                }
            }
        }

        return [ $invoiceNumber, $invoiceUrl ];
    }

    private function getCancelledByStornoIds(OrderEntity $order): array
    {
        $ids = [];
        if ($order->getDocuments()) {
            foreach ($order->getDocuments() as $document) {
                if (
                    in_array($document->getDocumentType()->getTechnicalName(), self::STORNO_TYPES, true) &&
                    $document->getReferencedDocumentId() !== null
                ) {
                    $ids[] = $document->getReferencedDocumentId();
                }
            }
        }
        return $ids;
    }

    private function getAdjustmentForCancelledCreditNotes(OrderEntity $order): array
    {
        $result = ['gross_amount_cents' => 0, 'cutoff' => null];

        if (!$order->getDocuments()) {
            return $result;
        }

        $latestStornoTime = null;
        foreach ($order->getDocuments() as $document) {
            if (in_array($document->getDocumentType()->getTechnicalName(), self::STORNO_TYPES, true)) {
                $docTime = $document->getCreatedAt();
                if ($latestStornoTime === null || $docTime > $latestStornoTime) {
                    $latestStornoTime = $docTime;
                }
            }
        }

        if ($latestStornoTime === null) {
            return $result;
        }

        $result['cutoff'] = $latestStornoTime;

        if ($order->getLineItems()) {
            foreach ($order->getLineItems() as $lineItem) {
                if ($lineItem->getType() !== LineItem::CREDIT_LINE_ITEM_TYPE) {
                    continue;
                }
                if ($lineItem->getCreatedAt() <= $latestStornoTime) {
                    $result['gross_amount_cents'] += (int) round(abs($lineItem->getPrice()->getTotalPrice()) * 100);
                }
            }
        }

        return $result;
    }
}
