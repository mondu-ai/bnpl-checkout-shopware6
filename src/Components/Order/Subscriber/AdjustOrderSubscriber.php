<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Subscriber;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryDefinition;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\ChangeSetAware;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Mondu\MonduPayment\Util\CriteriaHelper;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Psr\Log\LoggerInterface;

class AdjustOrderSubscriber implements EventSubscriberInterface
{
    private StateMachineRegistry $stateMachineRegistry;
    private EntityRepositoryInterface $orderRepository;
    private EntityRepositoryInterface $orderDataRepository;
    private EntityRepositoryInterface $invoiceDataRepository;
    private MonduClient $monduClient;
    private LoggerInterface $logger;

    public function __construct(StateMachineRegistry $stateMachineRegistry, EntityRepositoryInterface $orderRepository, EntityRepositoryInterface $orderDataRepository, EntityRepositoryInterface $invoiceDataRepository, MonduClient $monduClient, LoggerInterface $logger)
    {
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->orderRepository = $orderRepository;
        $this->orderDataRepository = $orderDataRepository;
        $this->invoiceDataRepository = $invoiceDataRepository;
        $this->monduClient = $monduClient;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PreWriteValidationEvent::class => 'triggerChangeSet',
            OrderEvents::ORDER_WRITTEN_EVENT => 'onOrderWritten',
        ];
    }

    public function triggerChangeSet(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommands() as $command) {
            if (!$command instanceof ChangeSetAware) {
                continue;
            }

            /** @var ChangeSetAware|InsertCommand|UpdateCommand $command */
            if ($command->getDefinition()->getEntityName() !== OrderDefinition::ENTITY_NAME) {
                continue;
            }

            $command->requestChangeSet();
        }
    }

    public function onOrderWritten(EntityWrittenEvent $event): void
    {
        try {
            foreach ($event->getWriteResults() as $result) {
                $changeSet = $result->getChangeSet();

                if ($result->getOperation() === EntityWriteResult::OPERATION_UPDATE
                && $changeSet != null
                && $changeSet->hasChanged('price')
                && count($event->getWriteResults()) > 1
            ) {
                    $context = $event->getContext();
                    $orderId = $result->getPrimaryKey();
                    $order = $this->getOrder($orderId, $context);

                    $criteria = new Criteria();
                    $criteria->addFilter(new EqualsFilter('orderId', $orderId));
                    $monduOrderEntity = $this->orderDataRepository->search($criteria, $context)->first();
                    
                    if (!isset($monduOrderEntity)) {
                        return;
                    }
                        
                    if ($monduOrderEntity->getOrderState() == 'canceled') {
                        $this->transitionDeliveryState($orderId, 'cancel', $context);
                    }

                    if ($this->hasInvoices($orderId, $context)) {
                        return;
                    }

                    $netPrice = 0;
                    $lineItems = [];
                    foreach ($order->getLineItems() as $lineItem) {
                        if ($lineItem->getType() !== \Shopware\Core\Checkout\Cart\LineItem\LineItem::PRODUCT_LINE_ITEM_TYPE) {
                            continue;
                        }

                        $unitNetPrice = ($lineItem->getPrice()->getUnitPrice() - ($lineItem->getPrice()->getCalculatedTaxes()->getAmount() / $lineItem->getQuantity())) * 100;
                        $lineItems[] = [
                            'external_reference_id' => $lineItem->getReferencedId(),
                            'quantity' => $lineItem->getQuantity(),
                            'title' => $lineItem->getLabel(),
                            'net_price_cents' => round($unitNetPrice * $lineItem->getQuantity()),
                            'net_price_per_item_cents' => round($unitNetPrice)
                        ];

                        $netPrice += $unitNetPrice * $lineItem->getQuantity();
                    }

                    $discountAmount = 0;

                    foreach ($order->getLineItems() as $lineItem) {
                        $discountLineItemType = 'discount';

                        if (defined( '\Shopware\Core\Checkout\Cart\LineItem\LineItem::DISCOUNT_LINE_ITEM'))
                            $discountLineItemType = \Shopware\Core\Checkout\Cart\LineItem\LineItem::DISCOUNT_LINE_ITEM;

                        if ($lineItem->getType() !== \Shopware\Core\Checkout\Cart\LineItem\LineItem::PROMOTION_LINE_ITEM_TYPE &&
                            $lineItem->getType() !== $discountLineItemType) {
                            continue;
                        }

                        $unitNetPrice = ($lineItem->getPrice()->getUnitPrice() - ($lineItem->getPrice()->getCalculatedTaxes()->getAmount() / $lineItem->getQuantity())) * 100;
                        $discountAmount += abs($unitNetPrice);
                    }

                    $shipping = ($order->getShippingCosts()->getUnitPrice() - ($order->getShippingCosts()->getCalculatedTaxes()->getAmount() / $order->getShippingCosts()->getQuantity()));

                    $adjustParams = [
                        'currency' => 'EUR',
                        'external_reference_id' => $order->getOrderNumber(),
                        'amount' => [
                            'net_price_cents' => round($netPrice),
                            'tax_cents' => round($order->getPrice()->getCalculatedTaxes()->getAmount() * 100),
                            'gross_amount_cents' => round($order->getPrice()->getTotalPrice() * 100)
                        ],
                        'lines' => [
                            [
                                'tax_cents' => round($order->getPrice()->getCalculatedTaxes()->getAmount() * 100),
                                'shipping_price_cents' => round($shipping * 100),
                                'discount_cents' => round($discountAmount),
                                'line_items' => $lineItems
                            ]
                        ]
                    ];

                    $response = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->adjustOrder(
                        $monduOrderEntity->getReferenceId(),
                        $adjustParams
                    );

                    if ($response == null) {
                        $this->log('Adjust Order Response Failed', [$event]);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->log('Adjust Order Failed', [$event], $e);
        }
    }

    protected function getOrder(string $orderId, Context $context): OrderEntity
    {
        $criteria = CriteriaHelper::getCriteriaForOrder($orderId);
        $criteria->addAssociation('documents.documentType');

        return $this->orderRepository->search($criteria, $context)->first();
    }

    protected function log($message, $data, $exception = null)
    {
        $exceptionMessage = "";

        if ($exception != null) {
            $exceptionMessage = $exception->getMessage();
        }

        $this->logger->critical(
            $message . '. (Exception: '. $exceptionMessage .')',
            $data
        );

        throw new MonduException('Adjusting an order failed. Please contact Mondu Support.');
    }

    protected function hasInvoices(string $orderId, $context)
    {
        $invoiceCriteria = new Criteria();
        $invoiceCriteria->addFilter(new EqualsFilter('orderId', $orderId));

        return $this->invoiceDataRepository->search($invoiceCriteria, $context)->getTotal() > 0;
    }

    protected function transitionDeliveryState($orderId, $state, $context)
    {
        try {
            $criteria = new Criteria([$orderId]);
            $criteria->addAssociation('deliveries');

            /** @var OrderEntity $orderEntity */
            $orderEntity = $this->orderRepository->search($criteria, $context)->first();
            $orderDeliveryId = $orderEntity->getDeliveries()->first()->getId();

            return $this->stateMachineRegistry->transition(new Transition(
                OrderDeliveryDefinition::ENTITY_NAME,
                $orderDeliveryId,
                $state,
                'stateId'
            ), $context);
        } catch (\Exception $e) {
            $this->log('Adjust Order: transitionDeliveryState Failed', [$orderId, $state], $e);
            throw new MonduException($e->getMessage());
        }
    }
}
