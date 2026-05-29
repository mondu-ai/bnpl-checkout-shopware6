<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Subscriber;

use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Services\OrderServices\AbstractOrderLinesService;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
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

class AdjustOrderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderDataRepository,
        private readonly EntityRepository $invoiceDataRepository,
        private readonly MonduClient $monduClient,
        private readonly LoggerInterface $logger,
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $currencyRepository,
        private readonly AbstractOrderLinesService $orderLinesService,
        private readonly ConfigService $configService
    ) {}

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

            if (!$command instanceof InsertCommand && !$command instanceof UpdateCommand) {
                continue;
            }

            if ($command->getEntityName() !== OrderDefinition::ENTITY_NAME) {
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
                    continue;
                }

                $payload = $result->getPayload();

                if (empty($payload)) {
                    continue;
                }

                $context = $event->getContext();
                $pk = $result->getPrimaryKey();
                $orderId = \is_array($pk) ? ($pk['id'] ?? reset($pk)) : $pk;
                $order = $this->getOrder($orderId, $context);
                if ($order === null) {
                    continue;
                }

                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('orderId', $orderId));
                $monduOrderEntity = $this->orderDataRepository->search($criteria, $context)->first();

                if (!isset($monduOrderEntity)) {
                    continue;
                }

                if ($this->hasInvoices($orderId, $context)) {
                    continue;
                }

                if ($this->hasCreditNoteItems($order)) {
                    if ($this->configService->isExtendedLogsEnabled()) {
                        $this->logger->info('mondu.INFO: Skipping adjust order call - credit note items present', [
                            'order_id' => $orderId,
                            'order_number' => $order->getOrderNumber()
                        ]);
                    }
                    continue;
                }

                $liveOrder = $this->monduClient
                    ->setSalesChannelId($order->getSalesChannelId())
                    ->getMonduOrder($monduOrderEntity->getReferenceId());

                if (!isset($liveOrder['real_price_cents'])) {
                    $this->logger->error(
                        'mondu.CRITICAL: Mondu API: Can not adjust order, API request is failing.',
                        ['monduOrder' => $monduOrderEntity]
                    );

                    continue;
                }

                $orderGrossAmountCents = round($order->getPrice()->getTotalPrice() * 100);
                $liveOrderPrice = $liveOrder['real_price_cents'];

                if ($orderGrossAmountCents == $liveOrderPrice) {
                    continue;
                }

                $netPrice = 0;
                foreach ($order->getLineItems() as $lineItem) {
                    if ($lineItem->getType() !== LineItem::PRODUCT_LINE_ITEM_TYPE) {
                        continue;
                    }

                    if ($lineItem->getPrice() === null) {
                        continue;
                    }

                    if ($order->getTaxStatus() === CartPrice::TAX_STATE_GROSS) {
                        $unitNetPrice = ($lineItem->getPrice()->getUnitPrice() - ($lineItem->getPrice()->getCalculatedTaxes()->getAmount() / $lineItem->getQuantity())) * 100;
                    } else {
                        $unitNetPrice = $lineItem->getPrice()->getUnitPrice() * 100;
                    }

                    $netPrice += $unitNetPrice * $lineItem->getQuantity();
                }

                $adjustParams = [
                    'currency' => $this->getCurrency($order->getCurrencyId(), $context)->getIsoCode(),
                    'external_reference_id' => $order->getOrderNumber(),
                    'amount' => [
                        'net_price_cents' => round($netPrice),
                        'tax_cents' => round($order->getPrice()->getCalculatedTaxes()->getAmount() * 100),
                        'gross_amount_cents' => round($order->getPrice()->getTotalPrice() * 100)
                    ],
                    'lines' => $this->orderLinesService->getLines($order, $context),
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

    protected function getOrder(string $orderId, Context $context): ?OrderEntity
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

        $this->logger->error(
            'mondu.CRITICAL: ' . $message . '. (Exception: '. $exceptionMessage .')',
            $data
        );
    }

    protected function hasInvoices(string $orderId, $context)
    {
        $invoiceCriteria = new Criteria();
        $invoiceCriteria->addFilter(new EqualsFilter('orderId', $orderId));

        return $this->invoiceDataRepository->search($invoiceCriteria, $context)->getTotal() > 0;
    }

    /**
     * Check if order contains credit note line items
     * Credit notes should not trigger adjust order calls to avoid API errors
     */
    protected function hasCreditNoteItems(OrderEntity $order): bool
    {
        foreach ($order->getLineItems() as $lineItem) {
            if ($lineItem->getType() === LineItem::CREDIT_LINE_ITEM_TYPE) {
                return true;
            }
        }

        return false;
    }
}
