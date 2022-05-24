<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Subscriber;

use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\ChangeSetAware;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Mondu\MonduPayment\Util\CriteriaHelper;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Psr\Log\LoggerInterface;


class AdjustOrderSubscriber implements EventSubscriberInterface
{
    private EntityRepositoryInterface $orderRepository;
    private EntityRepositoryInterface $orderDataRepository;
    private MonduClient $monduClient;
    private LoggerInterface $logger;

    public function __construct(EntityRepositoryInterface $orderRepository, EntityRepositoryInterface $orderDataRepository, MonduClient $monduClient, LoggerInterface $logger)
    {
        $this->orderRepository = $orderRepository;
        $this->orderDataRepository = $orderDataRepository;
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
            ) 
            {
                $context = $event->getContext();

                $orderId = $result->getPrimaryKey();
                $order = $this->getOrder($orderId, $context);

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
                        'net_price_per_item_cents' => round($unitNetPrice)
                    ];
                }

                $adjustParams = [
                  'currency' => 'EUR',
                  'external_reference_id' => $order->getOrderNumber(),
                  'amount' => [
                    'net_price_cents' => round($order->getPrice()->getNetPrice() * 100),
                    'tax_cents' => round($order->getPrice()->getCalculatedTaxes()->getAmount() * 100)
                  ],
                  'lines' => [
                    [
                      'tax_cents' => round($order->getPrice()->getCalculatedTaxes()->getAmount() * 100),
                      'shipping_price_cents' => round($order->getShippingCosts()->getTotalPrice() * 100),
                      'line_items' => $lineItems
                    ]
                  ]
                ];

                $criteria = new Criteria();
                $criteria->addFilter(new EqualsFilter('orderId', $orderId));
            
                $monduOrderEntity = $this->orderDataRepository->search($criteria, $context)->first();

                $response = $this->monduClient->adjustOrder(
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

    protected function log($message, $data, $exception = null) {
      $exceptionMessage = "";

      if ($exception != null) {
        $exceptionMessage = $exception->getMessage();
      }

      $this->logger->critical(
        $message . '. (Exception: '. $exceptionMessage .')',
        $data
      );
    }
}