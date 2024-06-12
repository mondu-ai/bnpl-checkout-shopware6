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
        $criteria->addAssociation('documents.documentType')->addAssociation('currency');
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

        $invoiceData = $this->invoiceDataService->getInvoiceData($order, $context);

        try {
            $invoice = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->invoiceOrder(
                $monduData->getReferenceId(),
                $invoiceData
            );

            if ($invoice == null) {
                throw new MonduException('Error ocurred while shipping an order. Please contact Mondu Support.');
            }
            $attachedDocument = $context->getExtensions()['mail-attachments']->getDocumentIds()[0];

            $this->invoiceDataRepository->upsert([
                [
                    InvoiceDataEntity::FIELD_ORDER_ID => $order->getId(),
                    InvoiceDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                    InvoiceDataEntity::FIELD_DOCUMENT_ID => $attachedDocument,
                    InvoiceDataEntity::FIELD_INVOICE_NUMBER => $invoiceData['external_reference_id'],
                    InvoiceDataEntity::FIELD_EXTERNAL_INVOICE_UUID => $invoice['uuid'],
                ]
            ], $context);

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
}
