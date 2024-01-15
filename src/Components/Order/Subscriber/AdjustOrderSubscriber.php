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
use Mondu\MonduPayment\Util\CriteriaHelper;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\Framework\Context;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;

/**
 * Adjust Order Subscriber Class
 */
class AdjustOrderSubscriber implements EventSubscriberInterface
{
    private StateMachineRegistry $stateMachineRegistry;
    private EntityRepository $orderRepository;
    private EntityRepository $orderDataRepository;
    private EntityRepository $invoiceDataRepository;
    private EntityRepository $productRepository;
    private EntityRepository $currencyRepository;
    private MonduClient $monduClient;
    private LoggerInterface $logger;

    /**
     * @param StateMachineRegistry $stateMachineRegistry
     * @param EntityRepository $orderRepository
     * @param EntityRepository $orderDataRepository
     * @param EntityRepository $invoiceDataRepository
     * @param MonduClient $monduClient
     * @param LoggerInterface $logger
     * @param EntityRepository $productRepository
     * @param EntityRepository $currencyRepository
     */
    public function __construct(
        StateMachineRegistry $stateMachineRegistry,
        EntityRepository $orderRepository,
        EntityRepository $orderDataRepository,
        EntityRepository $invoiceDataRepository,
        MonduClient $monduClient,
        LoggerInterface $logger,
        EntityRepository $productRepository,
        EntityRepository $currencyRepository
    ) {
        $this->stateMachineRegistry = $stateMachineRegistry;
        $this->orderRepository = $orderRepository;
        $this->orderDataRepository = $orderDataRepository;
        $this->invoiceDataRepository = $invoiceDataRepository;
        $this->monduClient = $monduClient;
        $this->logger = $logger;
        $this->productRepository = $productRepository;
        $this->currencyRepository = $currencyRepository;
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
                if ($result->getExistence() !== null && $result->getExistence()->exists()) {
                    break;
                }
    
                $payload = $result->getPayload();

                if (empty($payload)) {
                    continue;
                }

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

                $liveOrder = $this->monduClient->getMonduOrder($monduOrderEntity->getReferenceId());

                if (!isset($liveOrder['real_price_cents'])) {
                    $this->logger->critical(
                        'Mondu API: Can not adjust order, API request is failing.',
                        ['monduOrder' => $monduOrderEntity]
                    );

                    return;
                }

                $orderGrossAmountCents = round($order->getPrice()->getTotalPrice() * 100);
                $liveOrderPrice = $liveOrder['real_price_cents'];

                if ($orderGrossAmountCents == $liveOrderPrice) {
                    return;
                }

                $netPrice = 0;
                $lineItems = [];
                foreach ($order->getLineItems() as $lineItem) {
                    if ($lineItem->getType() !== \Shopware\Core\Checkout\Cart\LineItem\LineItem::PRODUCT_LINE_ITEM_TYPE) {
                        continue;
                    }

                    $product = $this->productRepository->search(new Criteria([$lineItem->getIdentifier()]), $context)->first();

                    if ($order->getTaxStatus() === CartPrice::TAX_STATE_GROSS) {
                        $unitNetPrice = ($lineItem->getPrice()->getUnitPrice() - ($lineItem->getPrice()->getCalculatedTaxes()->getAmount() / $lineItem->getQuantity())) * 100;
                    } else {
                        $unitNetPrice = $lineItem->getPrice()->getUnitPrice() * 100;
                    }

                    $lineItems[] = [
                        'external_reference_id' => $lineItem->getReferencedId(),
                        'product_id' => $product->getProductNumber(),
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

                    if (defined( '\Shopware\Core\Checkout\Cart\LineItem\LineItem::DISCOUNT_LINE_ITEM')) {
                        $discountLineItemType = \Shopware\Core\Checkout\Cart\LineItem\LineItem::DISCOUNT_LINE_ITEM;
                    }

                    if ($lineItem->getType() !== \Shopware\Core\Checkout\Cart\LineItem\LineItem::PROMOTION_LINE_ITEM_TYPE &&
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

                if ($order->getTaxStatus() === CartPrice::TAX_STATE_GROSS) {
                    $shipping = ($order->getShippingCosts()->getUnitPrice() - ($order->getShippingCosts()->getCalculatedTaxes()->getAmount() / $order->getShippingCosts()->getQuantity()));
                } else {
                    $shipping = $order->getShippingCosts()->getUnitPrice();
                }

                $adjustParams = [
                    'currency' => $this->getCurrency($order->getCurrencyId(), $context)->getIsoCode(),
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
        } catch (\Exception $e) {
            $this->log('Adjust Order Failed', [$event], $e);
            return;
        }
    }

    protected function getOrder(string $orderId, Context $context): OrderEntity
    {
        $criteria = CriteriaHelper::getCriteriaForOrder($orderId);
        $criteria->addAssociation('documents.documentType');

        return $this->orderRepository->search($criteria, $context)->first();
    }

    protected function getCurrency(string $currencyId, Context $context): CurrencyEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $currencyId));

        return $this->currencyRepository->search($criteria, $context)->first();
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
        }
    }
}
