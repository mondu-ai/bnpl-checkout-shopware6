<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\StateMachine\Subscriber;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
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
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
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
        private readonly AbstractInvoiceDataService $invoiceDataService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => "onTransition",
        ];
    }

    public function onTransition(StateMachineTransitionEvent $event): void
    {
        try {
            $eventName = $event->getEntityName();

            if ($eventName === OrderDeliveryDefinition::ENTITY_NAME) {
                $orderDelivery = $this->orderDeliveryRepository->search(new Criteria([$event->getEntityId()]), $event->getContext())->first();
                $order = $this->getOrder($orderDelivery->getOrderId(), $event->getContext());
            } elseif ($eventName === OrderDefinition::ENTITY_NAME) {
                $order = $this->getOrder($event->getEntityId(), $event->getContext());
            } else {
                return;
            }

            // Check if order is already cancelled to prevent "cannot be edited" errors
            if ($order->getStateMachineState()->getTechnicalName() === 'cancelled' && 
                $event->getToPlace()->getTechnicalName() === 'cancelled') {
                $this->logger->info('mondu.INFO: Order already cancelled, skipping transition subscriber', [
                    'order_id' => $order->getId(),
                    'order_number' => $order->getOrderNumber()
                ]);
                return;
            }

            $monduOrder = $this->getMonduDataFromOrder($order);

            if (!isset($monduOrder)) {
                return;
            }

            switch ($event->getToPlace()->getTechnicalName()) {
                case "cancelled":
                    try {
                        $state = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->cancelOrder($monduOrder->getReferenceId());
                        if ($state) {
                            $this->updateOrder($event->getContext(), $monduOrder, [
                                OrderDataEntity::FIELD_ORDER_STATE => $state
                            ]);
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning(
                            "mondu.INFO: Order cannot be cancelled in Mondu API: " . $e->getMessage(),
                            [
                                "order_id" => $order->getId(),
                                "mondu_reference_id" => $monduOrder->getReferenceId()
                            ]
                        );
                        // Continue with local cancellation even if Mondu API fails
                    }
                    break;
                case 'shipped':
                case 'shipped_partially':
                    $this->shipOrder($order, $event->getContext(), $monduOrder);
                    break;
            }
        } catch (\Throwable $e) {
            // Catch all errors including "cannot be edited" OrderException
            if (strpos($e->getMessage(), 'cannot be edited') !== false || 
                strpos($e->getMessage(), 'was cancelled') !== false) {
                $this->logger->info('mondu.INFO: Order was already cancelled, transition subscriber skipped gracefully', [
                    'event' => $event->getToPlace()->getTechnicalName(),
                    'error' => $e->getMessage()
                ]);
                return;
            }
            
            // Re-throw other exceptions
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

    private function shipOrder(OrderEntity $order, Context $context, OrderDataEntity $monduData): void
    {
        $monduData = $this->getMonduDataFromOrder($order);

        if ($monduData->getOrderState() === 'shipped') {
            return;
        }

        if ($this->configService->isSkippingAllMode()) {
            return;
        }

        if ($this->configService->isSkipAllValidationMode()) {
            // Try to get invoice URL from existing documents if available
            $invoiceUrl = 'https://example.com/invoice.pdf';  // Default placeholder URL
            $invoiceNumber = $order->getOrderNumber();
            $hasRealInvoice = false;
            
            if ($order->getDocuments() && $order->getDocuments()->count() > 0) {
                foreach ($order->getDocuments() as $document) {
                    if (
                        $document->getDocumentType()->getTechnicalName() === 'invoice' ||
                        $document->getDocumentType()->getTechnicalName() === 'zugferd_embedded_invoice'
                    ) {
                        $foundUrl = $this->invoiceDataService->getDocumentUrl($document);
                        if ($foundUrl !== null) {
                            $invoiceUrl = $foundUrl;
                            $hasRealInvoice = true;
                        }
                        $config = $document->getConfig();
                        $invoiceNumber = $config['custom']['invoiceNumber'] ?? $order->getOrderNumber();
                        break;
                    }
                }
            }
            
            // In skip all validation mode, always send invoice call (with real URL or placeholder)
            try {
                $invoiceData = [
                    'external_reference_id' => $invoiceNumber,
                    'gross_amount_cents' => (int)round($order->getAmountTotal() * 100),
                    'invoice_url' => $invoiceUrl  // Real URL or placeholder
                ];

                $this->logger->info(
                    'mondu.INFO: Skip all validation mode: Sending invoice to Mondu' . ($hasRealInvoice ? ' with real invoice URL' : ' with placeholder URL'),
                    [
                        'order' => $order->getId(),
                        'order_number' => $order->getOrderNumber(),
                        'mondu-reference-id' => $monduData->getReferenceId(),
                        'invoice_url' => $invoiceUrl,
                        'has_real_invoice' => $hasRealInvoice
                    ]
                );

                $invoice = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->invoiceOrder(
                    $monduData->getReferenceId(),
                    $invoiceData
                );

                if ($invoice != null) {
                    $this->invoiceDataRepository->upsert([
                        [
                            InvoiceDataEntity::FIELD_ORDER_ID => $order->getId(),
                            InvoiceDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                            InvoiceDataEntity::FIELD_DOCUMENT_ID => null,
                            InvoiceDataEntity::FIELD_INVOICE_NUMBER => $invoiceNumber,
                            InvoiceDataEntity::FIELD_EXTERNAL_INVOICE_UUID => $invoice['uuid'],
                        ]
                    ], $context);
                    
                    $this->logger->info(
                        'mondu.INFO: Skip all validation mode: Invoice successfully sent to Mondu',
                        [
                            'order' => $order->getId(),
                            'mondu-reference-id' => $monduData->getReferenceId(),
                            'invoice_uuid' => $invoice['uuid']
                        ]
                    );
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    'mondu.WARNING: Skip all validation mode: Invoice call failed (Exception: '. $e->getMessage().')',
                    [
                        'order' => $order->getId(),
                        'order_number' => $order->getOrderNumber(),
                        'mondu-reference-id' => $monduData->getReferenceId()
                    ]
                );
                // Don't throw - continue silently in skip all validation mode
            }
            
            // Update Mondu order state to shipped
            try {
                $this->updateOrder($context, $monduData, [
                    OrderDataEntity::FIELD_ORDER_STATE => 'shipped'
                ]);
            } catch (\Exception $e) {
                $this->logger->warning('mondu.WARNING: Failed to update Mondu order state to shipped', [
                    'order' => $order->getId(),
                    'error' => $e->getMessage()
                ]);
            }
            
            return;
        }

        $invoiceData = $this->invoiceDataService->getInvoiceData($order, $context);

        try {
            $invoice = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->invoiceOrder(
                $monduData->getReferenceId(),
                $invoiceData
            );

            if ($invoice == null) {
                throw new MonduException('Error ocurred while shipping an order. Please contact Mondu Support.');
            }
            
            // Get attached document if available (may not exist when manually changing delivery state)
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
            
            // Update Mondu order state to shipped
            $this->updateOrder($context, $monduData, [
                OrderDataEntity::FIELD_ORDER_STATE => 'shipped'
            ]);

        } catch (\Exception $e) {
            $this->logger->critical(
                'mondu.CRITICAL: Exception during shipment. (Exception: '. $e->getMessage().')',
                [
                    'order' => $order->getId(),
                    'mondu-reference-id' => $monduData->getReferenceId()
                ]
            );
            throw new MonduException('Error: ' . $e->getMessage());
        }
    }
}
