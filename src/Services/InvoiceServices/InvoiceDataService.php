<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Services\InvoiceServices;

use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class InvoiceDataService extends AbstractInvoiceDataService
{
    public function __construct(
        private readonly ConfigService $configService
    ) {}

    public function getDecorated(): AbstractInvoiceDataService
    {
        throw new DecorationPatternException(self::class);
    }

    public function getInvoiceData(OrderEntity $order, Context $context): array
    {
        [ $invoiceNumber, $invoiceUrl ] = $this->getInvoiceNumberAndUrl($order, $context);

        $isRequireInvoiceDocumentToShipEnabled = $this->configService->isRequireInvoiceDocumentToShipEnabled();

        return [
            'currency' => $this->orderUtilsService->getOrderCurrency($order),
            'external_reference_id' => $isRequireInvoiceDocumentToShipEnabled ? $order->getId() : $invoiceNumber,
            'invoice_url' => $isRequireInvoiceDocumentToShipEnabled ? '' : $invoiceUrl,
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

        $attachedDocument = $context->getExtensions()['mail-attachments']->getDocumentIds()[0];

        foreach ($order->getDocuments() as $document) {
            if ($document->getId() == $attachedDocument) {
                if ($document->getDocumentType()->getTechnicalName() === 'invoice') {
                    $config = $document->getConfig();
                    $invoiceNumber = $config['custom']['invoiceNumber'] ?? null;
                    $invoiceUrl = $this->documentUrlHelper->generateRouteForDocument($document);
                }
            }
        }

        return [ $invoiceNumber, $invoiceUrl ];
    }
}
