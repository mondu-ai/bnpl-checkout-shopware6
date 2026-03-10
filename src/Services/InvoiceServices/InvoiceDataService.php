<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\InvoiceServices;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class InvoiceDataService extends AbstractInvoiceDataService
{
    public function getDecorated(): AbstractInvoiceDataService
    {
        throw new DecorationPatternException(self::class);
    }

    public function getInvoiceData(OrderEntity $order, Context $context): array
    {
        [ $invoiceNumber, $invoiceUrl ] = $this->getInvoiceNumberAndUrl($order, $context);

        // Ensure external_reference_id is a string (Mondu API requirement)
        if ($invoiceNumber === null || $invoiceNumber === '') {
            throw new \RuntimeException('Invoice number is required but not found. Please create an invoice document for this order first.');
        }

        return [
            'currency' => $this->orderUtilsService->getOrderCurrency($order),
            'external_reference_id' => (string) $invoiceNumber,
            'invoice_url' => $invoiceUrl,
            'gross_amount_cents' => $this->orderUtilsService->priceToCents($order->getPrice()->getTotalPrice()),
            'discount_cents' => $this->orderDiscountService->getOrderDiscountCents($order, $context),
            'shipping_price_cents' => $this->orderUtilsService->getShippingPriceCents($order),
            'line_items' => $this->orderLineItemsService->getLineItems($order, $context, true)
        ];
    }

    protected function getInvoiceNumberAndUrl(OrderEntity $order, Context $context): array
    {
        $monduData = $this->orderUtilsService->getMonduDataFromOrder($order);

        $invoiceNumber = $monduData->getExternalInvoiceNumber();
        $invoiceUrl = $monduData->getExternalInvoiceUrl();

        // Check if mail-attachments extension exists (may not exist when manually changing delivery state)
        $attachedDocument = null;
        if ($context->hasExtension('mail-attachments')) {
            $mailAttachments = $context->getExtension('mail-attachments');
            $documentIds = $mailAttachments->getDocumentIds();
            if (!empty($documentIds)) {
                $attachedDocument = $documentIds[0];
            }
        }

        // Search for invoice document
        if ($order->getDocuments()) {
            foreach ($order->getDocuments() as $document) {
                // If we have an attached document, match it; otherwise look for any invoice
                if (($attachedDocument && $document->getId() == $attachedDocument) || !$attachedDocument) {
                    if (
                        $document->getDocumentType()->getTechnicalName() === 'invoice' ||
                        $document->getDocumentType()->getTechnicalName() === 'zugferd_embedded_invoice'
                    ) {
                        $config = $document->getConfig();
                        $invoiceNumber = $config['custom']['invoiceNumber'] ?? null;
                        $invoiceUrl = $this->documentUrlHelper->generateRouteForDocument($document);

                        // If we found a matching attached document, stop here
                        if ($attachedDocument && $document->getId() == $attachedDocument) {
                            break;
                        }
                    }
                }
            }
        }

        return [ $invoiceNumber, $invoiceUrl ];
    }
}
