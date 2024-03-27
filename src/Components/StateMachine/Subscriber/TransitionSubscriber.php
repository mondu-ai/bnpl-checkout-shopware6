<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\StateMachine\Subscriber;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\Order\Util\DocumentUrlHelper;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
use Mondu\MonduPayment\Util\CriteriaHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Document\DocumentGenerator\DeliveryNoteGenerator;
use Shopware\Core\Checkout\Document\DocumentGenerator\InvoiceGenerator;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mondu\MonduPayment\Components\Invoice\InvoiceDataEntity;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class TransitionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderDeliveryRepository,
        private readonly EntityRepository $orderRepository,
        private readonly ConfigService $configService,
        private readonly MonduClient $monduClient,
        private readonly EntityRepository $orderDataRepository,
        private readonly EntityRepository $invoiceDataRepository,
        private readonly DocumentUrlHelper $documentUrlHelper,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $currencyRepository
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onTransition',
        ];
    }

    public function onTransition(StateMachineTransitionEvent $event): void
    {
        $eventName = $event->getEntityName();

        if ($eventName === OrderDeliveryDefinition::ENTITY_NAME) {
            $orderDelivery = $this->orderDeliveryRepository->search(new Criteria([$event->getEntityId()]), $event->getContext())->first();
            $order = $this->getOrder($orderDelivery->getOrderId(), $event->getContext());
        } elseif ($eventName === OrderDefinition::ENTITY_NAME) {
            $order = $this->getOrder($event->getEntityId(), $event->getContext());
        } else {
            return;
        }

        $monduOrder = $this->getMonduDataFromOrder($order);

        if (!isset($monduOrder)) {
            return;
        }

        switch ($event->getToPlace()->getTechnicalName()) {
            case 'cancelled':
                $state = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->cancelOrder($monduOrder->getReferenceId());
                if ($state) {
                    $this->updateOrder($event->getContext(), $monduOrder, [
                        OrderDataEntity::FIELD_ORDER_STATE => $state
                    ]);
                }
                break;
            case 'shipped':
            case 'shipped_partially':
                $this->shipOrder($order, $event->getContext(), $monduOrder);
                break;
        }
    }

    protected function getOrder(string $orderId, Context $context): OrderEntity
    {
        $criteria = CriteriaHelper::getCriteriaForOrder($orderId);
        $criteria->addAssociation('documents.documentType');

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

        if ($this->configService->skipOrderStateValidation()) {
            return;
        }

        $invoiceNumber = $monduData->getExternalInvoiceNumber();
        $invoiceUrl = $monduData->getExternalInvoiceUrl();
        $shippingUrl = $monduData->getExternalDeliveryNoteUrl();

        $attachedDocument = $context->getExtensions()['mail-attachments']->getDocumentIds()[0];

        foreach ($order->getDocuments() as $document) {
            if ($document->getId() == $attachedDocument) {
                if ($document->getDocumentType()->getTechnicalName() === 'invoice') {
                    $config = $document->getConfig();
                    $invoiceNumber = $config['custom']['invoiceNumber'] ?? null;
                    $invoiceUrl = $this->documentUrlHelper->generateRouteForDocument($document);
                }
            }

            if ($document->getDocumentType()->getTechnicalName() === 'delivery_note') {
                $shippingUrl = $this->documentUrlHelper->generateRouteForDocument($document);
            }
        }

        if ($order->getTaxStatus() === CartPrice::TAX_STATE_GROSS) {
            $shipping = ($order->getShippingCosts()->getUnitPrice() - ($order->getShippingCosts()->getCalculatedTaxes()->getAmount() / $order->getShippingCosts()->getQuantity()));
        } else {
            $shipping = $order->getShippingCosts()->getUnitPrice();
        }

        try {
            $invoice = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->invoiceOrder(
                $monduData->getReferenceId(),
                $invoiceNumber,
                round((float) $order->getPrice()->getTotalPrice() * 100),
                $invoiceUrl,
                $this->getLineItems($order, $context),
                $this->getDiscount($order, $context),
                round($shipping * 100),
                $this->getCurrency($order->getCurrencyId(), $context)->getIsoCode()
            );

            if ($invoice == null) {
                throw new MonduException('Error ocurred while shipping an order. Please contact Mondu Support.');
            }

            if ($invoice) {
                $this->invoiceDataRepository->upsert([
                [
                    InvoiceDataEntity::FIELD_ORDER_ID => $order->getId(),
                    InvoiceDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                    InvoiceDataEntity::FIELD_DOCUMENT_ID => $attachedDocument,
                    InvoiceDataEntity::FIELD_INVOICE_NUMBER => $invoiceNumber,
                    InvoiceDataEntity::FIELD_EXTERNAL_INVOICE_UUID => $invoice['uuid'],
                ]
              ], $context);
            }
        } catch (\Exception $e) {
            $this->logger->critical(
                'Exception during shipment. (Exception: '. $e->getMessage().')',
                [
                    'order' => $order->getId(),
                    'mondu-reference-id' => $monduData->getReferenceId()
                ]
            );
            throw new MonduException('Error: ' . $e->getMessage());
        }
    }

    protected function getLineItems($order, Context $context): array
    {
        $collection = $order->getLineItems();

        $lineItems = [];
        /** @var LineItem|OrderLineItemEntity $lineItem */
        foreach ($collection->getIterator() as $lineItem) {
            if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                continue;
            }

            $lineItems[] = [
                'external_reference_id' => $lineItem->getReferencedId(),
                'quantity' => $lineItem->getQuantity()
            ];
        }

        return $lineItems;
    }

    protected function getDiscount($order, Context $context): float
    {
        $collection = $order->getLineItems();

        $discountAmount = 0;
        /** @var LineItem|OrderLineItemEntity $lineItem */
        foreach ($collection->getIterator() as $lineItem) {
            $discountLineItemType = 'discount';

            if (defined( '\Shopware\Core\Checkout\Cart\LineItem\LineItem::DISCOUNT_LINE_ITEM')) {
                $discountLineItemType = LineItem::DISCOUNT_LINE_ITEM;
            }

            if ($lineItem->getType() !== LineItem::PROMOTION_LINE_ITEM_TYPE &&
                $lineItem->getType() !== $discountLineItemType) {
                continue;
            }

            if ($order->getTaxStatus() === CartPrice::TAX_STATE_GROSS) {
                $unitNetPrice = ($lineItem->getPrice()->getUnitPrice() - ($lineItem->getPrice()->getCalculatedTaxes()->getAmount() / $lineItem->getQuantity())) * 100;
            } else {
                $unitNetPrice = $lineItem->getPrice()->getUnitPrice() * 100;
            }

            $discountAmount += abs($unitNetPrice);
        }

        return $discountAmount;
    }

    protected function getCurrency(string $currencyId, Context $context): CurrencyEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $currencyId));

        return $this->currencyRepository->search($criteria, $context)->first();
    }
}
