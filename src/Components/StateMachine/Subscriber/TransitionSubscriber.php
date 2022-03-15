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
use Shopware\Core\Checkout\Document\DocumentGenerator\DeliveryNoteGenerator;
use Shopware\Core\Checkout\Document\DocumentGenerator\InvoiceGenerator;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TransitionSubscriber implements EventSubscriberInterface
{
    private ConfigService $configService;
    private EntityRepositoryInterface $orderDeliveryRepository;
    private EntityRepositoryInterface $orderRepository;
    private $operationService;
    private $monduClient;
    private $orderDataRepository;
    private $documentUrlHelper;
    private $logger;

    public function __construct(
        EntityRepositoryInterface $orderDeliveryRepository,
        EntityRepositoryInterface $orderRepository,
        ConfigService $configService,
        MonduClient $monduClient,
        EntityRepositoryInterface $orderDataRepository,
        DocumentUrlHelper $documentUrlHelper,
        LoggerInterface $logger
    ) {
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->orderRepository = $orderRepository;
        $this->configService = $configService;
        $this->monduClient = $monduClient;
        $this->orderDataRepository = $orderDataRepository;
        $this->documentUrlHelper = $documentUrlHelper;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents()
    {
        return [
            StateMachineTransitionEvent::class => 'onTransition',
        ];
    }

    public function onTransition(StateMachineTransitionEvent $event) {
        //TODO config service stuff idk what?
        if(false) {
            return;
        }

        $eventName = $event->getEntityName();

        if($eventName === OrderDeliveryDefinition::ENTITY_NAME) {
            $orderDelivery = $this->orderDeliveryRepository->search(new Criteria([$event->getEntityId()]), $event->getContext())->first();
            $order = $this->getOrder($orderDelivery->getOrderId(), $event->getContext());
        } elseif ($eventName === OrderDefinition::ENTITY_NAME) {
            $order = $this->getOrder($event->getEntityId(), $event->getContext());
        } else {
            return;
        }

        $monduOrder = $this->getMonduDataFromOrder($order);

        switch ($event->getToPlace()->getTechnicalName()) {
            case 'cancelled': //$this->configService->getStateCancel():
                $state = $this->monduClient->cancelOrder($monduOrder->getReferenceId());
                if($state) {
                    $this->updateOrder($event->getContext(), $monduOrder, [
                        OrderDataEntity::FIELD_ORDER_STATE => $state
                    ]);
                }
                break;
            case 'shipped':
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

    private function getMonduDataFromOrder(OrderEntity $order): OrderDataEntity {
        $monduData = $order->getExtension(OrderExtension::EXTENSION_NAME);

        if (!$monduData instanceof OrderDataEntity) {
            throw new \RuntimeException('The order `' . $order->getId() . '` is not a mondu order, or the mondu order data extension has not been loaded');
        }

        return $monduData;
    }

    private function updateOrder(Context $context, OrderDataEntity $monduData, array $data): void {
        $updateData = $data;
        $updateData[OrderDataEntity::FIELD_ID] = $monduData->getId();

        $this->orderDataRepository->update([
            $updateData
        ], $context);
    }

    private function shipOrder(OrderEntity $order, Context $context, OrderDataEntity $monduData): bool {
        $monduData = $this->getMonduDataFromOrder($order);

        if($monduData->getOrderState() === 'shipped') {
            return true;
        }

        $invoiceNumber = $monduData->getExternalInvoiceNumber();
        $invoiceUrl = $monduData->getExternalInvoiceUrl();
        $shippingUrl = $monduData->getExternalDeliveryNoteUrl();

        if (!$invoiceNumber || !$shippingUrl) {
            foreach ($order->getDocuments() as $document) {
                if ($invoiceNumber === null &&
                    $document->getDocumentType()->getTechnicalName() === InvoiceGenerator::INVOICE
                ) {
                    $config = $document->getConfig();
                    $invoiceNumber = $config['custom']['invoiceNumber'] ?? null;
                    $invoiceUrl = $this->documentUrlHelper->generateRouteForDocument($document);
                }

                if ($shippingUrl === null &&
                    $document->getDocumentType()->getTechnicalName() === DeliveryNoteGenerator::DELIVERY_NOTE
                ) {
                    $shippingUrl = $this->documentUrlHelper->generateRouteForDocument($document);
                }
            }
        }

        try {
            $invoice = $this->monduClient->invoiceOrder(
                $monduData->getReferenceId(),
                $order->getOrderNumber(),
                (int) $order->getPrice()->getTotalPrice() * 100,
                $invoiceUrl
            );

            $this->updateOrder(
                $context,
                $monduData,
                [
                    OrderDataEntity::FIELD_ORDER_STATE => $invoice['order']['state'],
                    OrderDataEntity::FIELD_VIBAN => $invoice['order']['buyer']['viban']
                ]
            );
        } catch (\Exception $e) {
            $this->logger->critical(
                'Exception during shipment. (Exception: '. $e->getMessage().')',
                [
                    'order' => $order->getId(),
                    'mondu-reference-id' => $monduData->getReferenceId()
                ]
            );
            throw new MonduException('Error shipping an order, check application logs for more details');
        }

        return true;
    }
}