<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\StateMachine\Subscriber;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\Order\Util\DocumentUrlHelper;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduInvoiceException;
use Mondu\MonduPayment\Services\InvoiceServices\AbstractInvoiceDataService;
use Mondu\MonduPayment\Util\CriteriaHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mondu\MonduPayment\Components\Invoice\InvoiceDataEntity;

class TransitionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderDeliveryRepository,
        private readonly EntityRepository $orderRepository,
        private readonly ConfigService $configService,
        private readonly MonduClient $monduClient,
        private readonly EntityRepository $orderDataRepository,
        private readonly EntityRepository $invoiceDataRepository,
        private readonly LoggerInterface $logger,
        private readonly AbstractInvoiceDataService $invoiceDataService,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly DocumentUrlHelper $documentUrlHelper
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onTransition',
        ];
    }

    public function onTransition(StateMachineTransitionEvent $event): void
    {
        try {
            $eventName = $event->getEntityName();
            $deliveryId = null;

            if ($eventName === OrderDeliveryDefinition::ENTITY_NAME) {
                $deliveryId = $event->getEntityId();
                $orderDelivery = $this->orderDeliveryRepository->search(new Criteria([$deliveryId]), $event->getContext())->first();
                $order = $this->getOrder($orderDelivery->getOrderId(), $event->getContext());
            } elseif ($eventName === OrderDefinition::ENTITY_NAME) {
                $order = $this->getOrder($event->getEntityId(), $event->getContext());
            } else {
                return;
            }

            if ($order->getStateMachineState()->getTechnicalName() === 'cancelled' &&
                $event->getToPlace()->getTechnicalName() === 'cancelled') {
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Order already cancelled, skipping transition subscriber', [
                        'order_id' => $order->getId(),
                        'order_number' => $order->getOrderNumber()
                    ]);
                }
                return;
            }

            $monduOrder = $this->getMonduDataFromOrder($order);

            if (!isset($monduOrder)) {
                return;
            }

            switch ($event->getToPlace()->getTechnicalName()) {
                case 'cancelled':
                    try {
                        $state = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->cancelOrder($monduOrder->getReferenceId());
                        if ($state) {
                            $this->updateOrder($event->getContext(), $monduOrder, [
                                OrderDataEntity::FIELD_ORDER_STATE => $state
                            ]);
                        }
                    } catch (\Exception $e) {
                        if ($this->configService->isExtendedLogsEnabled()) {
                            $this->logger->warning(
                                'mondu.INFO: Order cannot be cancelled in Mondu API: ' . $e->getMessage(),
                                [
                                    'order_id' => $order->getId(),
                                    'mondu_reference_id' => $monduOrder->getReferenceId()
                                ]
                            );
                        }
                    }
                    break;
                case 'shipped':
                case 'shipped_partially':
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: About to call shipOrder', [
                            'order' => $order->getId(),
                            'delivery_id' => $deliveryId,
                            'transition' => $event->getToPlace()->getTechnicalName()
                        ]);
                    }
                    $this->shipOrder($order, $event->getContext(), $monduOrder, $deliveryId);
                    break;
            }
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'cannot be edited') !== false ||
                strpos($e->getMessage(), 'was cancelled') !== false) {
                if ($this->configService->isExtendedLogsEnabled()) {
                    $this->logger->info('mondu.INFO: Order was already cancelled, transition subscriber skipped gracefully', [
                        'event' => $event->getToPlace()->getTechnicalName(),
                        'error' => $e->getMessage()
                    ]);
                }
                return;
            }

            throw $e;
        }
    }

    protected function getOrder(string $orderId, Context $context): OrderEntity
    {
        $criteria = CriteriaHelper::getCriteriaForOrder($orderId);
        $criteria->addAssociation('documents.documentType')
            ->addAssociation('currency')
            ->addAssociation('stateMachineState');
        return $this->orderRepository->search($criteria, $context)->first();
    }

    private function getMonduDataFromOrder(OrderEntity $order): ?Struct
    {
        return $order->getExtension(OrderExtension::EXTENSION_NAME);
    }

    private function updateOrder(Context $context, OrderDataEntity $monduData, array $data): void
    {
        $updateData = $data;
        $updateData[OrderDataEntity::FIELD_ID] = $monduData->getId();

        $this->orderDataRepository->update([
            $updateData
        ], $context);
    }

    private function shipOrder(OrderEntity $order, Context $context, OrderDataEntity $monduData, ?string $deliveryId = null): void
    {
        if ($this->configService->isExtendedLogsEnabled()) {
            $this->logger->info('mondu.INFO: shipOrder() called', [
                'order' => $order->getId(),
                'delivery_id' => $deliveryId
            ]);
        }

        $monduData = $this->getMonduDataFromOrder($order);

        if ($monduData->getOrderState() === 'shipped') {
            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Order already shipped, returning');
            }
            return;
        }

        // Check if invoice already exists in DB
        $invoiceCriteria = new Criteria();
        $invoiceCriteria->addFilter(new EqualsFilter('orderId', $order->getId()));
        $existingInvoice = $this->invoiceDataRepository->search($invoiceCriteria, $context)->first();

        if ($existingInvoice) {
            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: Invoice already exists in DB, updating order state to shipped', [
                    'order' => $order->getId(),
                    'existing_invoice_id' => $existingInvoice->getId()
                ]);
            }

            try {
                $this->updateOrder($context, $monduData, [
                    OrderDataEntity::FIELD_ORDER_STATE => 'shipped'
                ]);
                return;
            } catch (\Exception $e) {
                $this->logger->error('mondu.ERROR: Failed to update order state to shipped', [
                    'order' => $order->getId(),
                    'error' => $e->getMessage()
                ]);
                return;
            }
        }

        // Skip all mode: return immediately
        if ($this->configService->isSkippingAllMode()) {
            return;
        }

        // Skip all validation mode: send invoice without document requirement
        if ($this->configService->isSkipAllValidationMode()) {
            $invoiceUrl = 'https://example.com/invoice.pdf';
            $invoiceNumber = $order->getOrderNumber();
            $hasRealInvoice = false;
            $documentId = null;

            if ($order->getDocuments() && $order->getDocuments()->count() > 0) {
                // Prefer the document explicitly selected by the admin in the UI
                $selectedDocumentIds = [];
                if ($context->hasExtension('mail-attachments')) {
                    $mailAttachments = $context->getExtension('mail-attachments');
                    $selectedDocumentIds = $mailAttachments->getDocumentIds();
                }

                $chosenDoc = null;

                // First: try to find the selected document among invoice-type docs
                if (!empty($selectedDocumentIds)) {
                    foreach ($order->getDocuments() as $document) {
                        if (
                            in_array($document->getId(), $selectedDocumentIds) &&
                            (
                                $document->getDocumentType()->getTechnicalName() === 'invoice' ||
                                $document->getDocumentType()->getTechnicalName() === 'zugferd_embedded_invoice'
                            )
                        ) {
                            $chosenDoc = $document;
                            break;
                        }
                    }
                }

                // Fallback: take the newest active (non-cancelled) invoice document
                if ($chosenDoc === null) {
                    $cancelledByStornoIds = [];
                    foreach ($order->getDocuments() as $document) {
                        if ($document->getReferencedDocumentId() !== null) {
                            $cancelledByStornoIds[] = $document->getReferencedDocumentId();
                        }
                    }

                    $invoiceDocs = [];
                    foreach ($order->getDocuments() as $document) {
                        if (
                            (
                                $document->getDocumentType()->getTechnicalName() === 'invoice' ||
                                $document->getDocumentType()->getTechnicalName() === 'zugferd_embedded_invoice'
                            ) &&
                            !in_array($document->getId(), $cancelledByStornoIds)
                        ) {
                            $invoiceDocs[] = $document;
                        }
                    }
                    usort($invoiceDocs, function ($a, $b) {
                        return $b->getCreatedAt() <=> $a->getCreatedAt();
                    });
                    $chosenDoc = $invoiceDocs[0] ?? null;
                }

                if ($chosenDoc !== null) {
                    $foundUrl = $this->documentUrlHelper->generateRouteForDocument($chosenDoc);
                    if ($foundUrl !== null) {
                        $invoiceUrl = $foundUrl;
                        $hasRealInvoice = true;
                    }
                    $config = $chosenDoc->getConfig();
                    $invoiceNumber = $config['custom']['invoiceNumber'] ?? $order->getOrderNumber();
                    $documentId = $chosenDoc->getId();
                }
            }

            $invoice = null;

            try {
                $reflection = new \ReflectionClass($this->invoiceDataService);
                $orderLineItemsServiceProperty = $reflection->getProperty('orderLineItemsService');
                $orderLineItemsServiceProperty->setAccessible(true);
                $orderLineItemsService = $orderLineItemsServiceProperty->getValue($this->invoiceDataService);

                $orderUtilsServiceProperty = $reflection->getProperty('orderUtilsService');
                $orderUtilsServiceProperty->setAccessible(true);
                $orderUtilsService = $orderUtilsServiceProperty->getValue($this->invoiceDataService);

                $orderDiscountServiceProperty = $reflection->getProperty('orderDiscountService');
                $orderDiscountServiceProperty->setAccessible(true);
                $orderDiscountService = $orderDiscountServiceProperty->getValue($this->invoiceDataService);

                $lineItemDocId = $documentId ?? $order->getOrderNumber();
                $lineItems = $orderLineItemsService->getLineItems($order, $context, true);

                foreach ($lineItems as &$lineItem) {
                    $lineItem['documentId'] = $lineItemDocId;
                }
                unset($lineItem);

                $invoiceData = [
                    'currency' => $orderUtilsService->getOrderCurrency($order),
                    'external_reference_id' => (string) $invoiceNumber,
                    'invoice_url' => $invoiceUrl,
                    'gross_amount_cents' => $orderUtilsService->priceToCents($order->getPrice()->getTotalPrice()),
                    'discount_cents' => $orderDiscountService->getOrderDiscountCents($order, $context),
                    'shipping_price_cents' => $orderUtilsService->getShippingPriceCents($order),
                    'line_items' => $lineItems
                ];
                $invoiceData = $this->addShipmentDetailsToInvoiceData($invoiceData, $order, $deliveryId);

                $invoice = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->invoiceOrder(
                    $monduData->getReferenceId(),
                    $invoiceData
                );

                if ($invoice != null && isset($invoice['uuid'])) {
                    if ($documentId !== null) {
                        $this->invoiceDataRepository->upsert([
                            [
                                InvoiceDataEntity::FIELD_ORDER_ID => $order->getId(),
                                InvoiceDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                                InvoiceDataEntity::FIELD_DOCUMENT_ID => $documentId,
                                InvoiceDataEntity::FIELD_INVOICE_NUMBER => $invoiceNumber,
                                InvoiceDataEntity::FIELD_EXTERNAL_INVOICE_UUID => $invoice['uuid'],
                            ]
                        ], $context);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('mondu.ERROR: Skip all validation mode: Invoice call failed', [
                    'order' => $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'mondu-reference-id' => $monduData->getReferenceId(),
                    'error' => $e->getMessage()
                ]);
            }

            if (is_array($invoice) && isset($invoice['status']) && $invoice['status'] === 'already_exists') {
                if ($deliveryId !== null) {
                    try {
                        $this->stateMachineRegistry->transition(new Transition(
                            OrderDeliveryDefinition::ENTITY_NAME,
                            $deliveryId,
                            'reopen',
                            'stateId'
                        ), $context);
                    } catch (\Exception $revertEx) {}
                }
                throw new MonduInvoiceException('Invoice already exists in Mondu. Please cancel the existing invoice before shipping.');
            }

            if ($invoice === null) {
                if ($deliveryId !== null) {
                    try {
                        $this->stateMachineRegistry->transition(new Transition(
                            OrderDeliveryDefinition::ENTITY_NAME,
                            $deliveryId,
                            'reopen',
                            'stateId'
                        ), $context);
                    } catch (\Exception $revertEx) {
                        $this->logger->error('mondu.ERROR: Failed to revert delivery state after invoice failure', [
                            'order' => $order->getId(),
                            'delivery_id' => $deliveryId,
                            'error' => $revertEx->getMessage()
                        ]);
                    }
                }
                throw new MonduInvoiceException('Error occurred while shipping an order. Invoice API call failed. Please contact Mondu Support.');
            }

            try {
                $this->updateOrder($context, $monduData, [
                    OrderDataEntity::FIELD_ORDER_STATE => 'shipped'
                ]);
            } catch (\Exception $e) {
                $this->logger->error('mondu.ERROR: Failed to update Mondu order state to shipped', [
                    'order' => $order->getId(),
                    'error' => $e->getMessage()
                ]);
            }

            return;
        }

        $invoiceData = $this->addShipmentDetailsToInvoiceData(
            $this->invoiceDataService->getInvoiceData($order, $context),
            $order,
            $deliveryId
        );

        if ($this->configService->isExtendedLogsEnabled()) {
            $this->logger->info('mondu.INFO: Invoice data before API call', [
                'order' => $order->getId(),
                'invoice_data' => array_diff_key($invoiceData, ['line_items' => null])
            ]);
        }

        try {
            $invoice = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->invoiceOrder(
                $monduData->getReferenceId(),
                $invoiceData
            );

            if (is_array($invoice) && isset($invoice['status']) && $invoice['status'] === 'already_exists') {
                throw new MonduInvoiceException('Invoice already exists in Mondu. Please cancel the existing invoice before shipping.');
            }

            if ($invoice == null) {
                throw new MonduInvoiceException('Error occurred while shipping an order. Invoice API call failed. Please contact Mondu Support.');
            }

            $attachedDocument = null;
            if ($context->hasExtension('mail-attachments')) {
                $mailAttachments = $context->getExtension('mail-attachments');
                $documentIds = $mailAttachments->getDocumentIds();
                if (!empty($documentIds)) {
                    $attachedDocument = $documentIds[0];
                }
            }

            $this->invoiceDataRepository->upsert([
                [
                    InvoiceDataEntity::FIELD_ORDER_ID => $order->getId(),
                    InvoiceDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                    InvoiceDataEntity::FIELD_DOCUMENT_ID => $attachedDocument,
                    InvoiceDataEntity::FIELD_INVOICE_NUMBER => $invoiceData['external_reference_id'],
                    InvoiceDataEntity::FIELD_EXTERNAL_INVOICE_UUID => $invoice['uuid'],
                ]
            ], $context);

            $this->updateOrder($context, $monduData, [
                OrderDataEntity::FIELD_ORDER_STATE => 'shipped'
            ]);

        } catch (\Exception $e) {
            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->warning(
                    'mondu.WARNING: Exception during shipment. (Exception: '. $e->getMessage().')',
                    [
                        'order' => $order->getId(),
                        'mondu-reference-id' => $monduData->getReferenceId(),
                        'delivery_id' => $deliveryId
                    ]
                );
            }

            // Revert delivery state on failure
            if ($deliveryId !== null) {
                try {
                    $this->stateMachineRegistry->transition(new Transition(
                        OrderDeliveryDefinition::ENTITY_NAME,
                        $deliveryId,
                        'reopen',
                        'stateId'
                    ), $context);

                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Delivery state reverted to open due to shipment failure', [
                            'order' => $order->getId(),
                            'delivery_id' => $deliveryId
                        ]);
                    }
                } catch (\Exception $revertEx) {
                    $this->logger->error('mondu.ERROR: Failed to revert delivery state after shipment failure', [
                        'order' => $order->getId(),
                        'delivery_id' => $deliveryId,
                        'error' => $revertEx->getMessage()
                    ]);
                }
            }

            throw new MonduInvoiceException('Error occurred while shipping an order. Invoice API call failed. Please contact Mondu Support.');
        }
    }

    /**
     * Adds shipment details (tracking_number, shipping_company) to invoice request body.
     */
    private function addShipmentDetailsToInvoiceData(array $invoiceData, OrderEntity $order, ?string $deliveryId): array
    {
        $deliveries = $order->getDeliveries();
        if ($deliveries === null || $deliveries->count() === 0) {
            return $invoiceData;
        }

        $delivery = null;
        if ($deliveryId !== null) {
            foreach ($deliveries as $d) {
                if ($d->getId() === $deliveryId) {
                    $delivery = $d;
                    break;
                }
            }
        }
        if ($delivery === null) {
            $delivery = $deliveries->first();
        }
        if ($delivery === null) {
            return $invoiceData;
        }

        $trackingCodes = $delivery->getTrackingCodes();
        $trackingNumber = null;
        if ($trackingCodes !== null) {
            if (is_array($trackingCodes)) {
                $trackingNumber = !empty($trackingCodes) ? reset($trackingCodes) : null;
            } else {
                $trackingNumber = $trackingCodes->count() > 0 ? $trackingCodes->first() : null;
            }
        }

        $shippingMethod = $delivery->getShippingMethod();
        $shippingCompany = null;
        $shippingMethodName = null;
        $trackingUrl = null;

        if ($shippingMethod !== null) {
            $shippingCompany = $shippingMethod->getName();
            $shippingMethodName = $shippingMethod->getName();
            $templateUrl = $shippingMethod->getTrackingUrl();
            if ($templateUrl !== null && $templateUrl !== '' && $trackingNumber !== null) {
                $trackingUrl = str_replace('%s', (string) $trackingNumber, $templateUrl);
            }
        }

        $shippingInfo = [];
        if ($trackingNumber !== null) {
            $shippingInfo['tracking_number'] = (string) $trackingNumber;
        }
        if ($trackingUrl !== null) {
            $shippingInfo['tracking_url'] = $trackingUrl;
        }
        if ($shippingCompany !== null) {
            $shippingInfo['shipping_company'] = (string) $shippingCompany;
        }
        if ($shippingMethodName !== null && $shippingMethodName !== '') {
            $shippingInfo['shipping_method'] = (string) $shippingMethodName;
        }

        return $shippingInfo !== [] ? array_merge($invoiceData, ['shipping_info' => $shippingInfo]) : $invoiceData;
    }
}
