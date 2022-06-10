<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Subscriber;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Util\CriteriaHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\ChangeSetAware;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;

class CreditNoteSubscriber implements EventSubscriberInterface
{
    private EntityRepositoryInterface $orderRepository;
    private EntityRepositoryInterface $orderDataRepository;
    private EntityRepositoryInterface $invoiceDataRepository;
    private MonduClient $monduClient;
    private LoggerInterface $logger;

    public function __construct(EntityRepositoryInterface $orderRepository, EntityRepositoryInterface $orderDataRepository, EntityRepositoryInterface $invoiceDataRepository, MonduClient $monduClient, LoggerInterface $logger)
    {
        $this->orderRepository = $orderRepository;
        $this->orderDataRepository = $orderDataRepository;
        $this->invoiceDataRepository = $invoiceDataRepository;
        $this->monduClient = $monduClient;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'document.written' => 'onDocumentWritten',
        ];
    }

    public function onDocumentWritten(EntityWrittenEvent $event): void
    {
        try {
            $writeResult = $event->getWriteResults();

            if (count($writeResult) > 0) {
                $payload = $writeResult[0]->getPayload();
                $creditNoteNumber = @$payload['config']['custom']['creditNoteNumber'];

                if ($payload['config']['name'] == 'credit_note' && $creditNoteNumber != null) {
                    $orderId = $payload['orderId'];
                    $invoiceNumber = $payload['config']['custom']['invoiceNumber'];

                    $invoiceCriteria = new Criteria();
                    $invoiceCriteria->addFilter(new EqualsFilter('invoiceNumber', $invoiceNumber));
                    $invoiceCriteria->addFilter(new EqualsFilter('orderId', $orderId));
                    $invoiceEntity = $this->invoiceDataRepository->search($invoiceCriteria, $event->getContext())->first();

                    $order = $this->getOrder($orderId, $event->getContext());

                    $grossAmountCents = 0;
                    $taxCents = 0;
                    foreach ($order->getLineItems() as $lineItem) {
                        if ($lineItem->getType() !== \Shopware\Core\Checkout\Cart\LineItem\LineItem::CREDIT_LINE_ITEM_TYPE) {
                            continue;
                        }

                        $grossAmountCents += round(abs($lineItem->getPrice()->getTotalPrice()) * 100);
                        $taxCents += round(abs($lineItem->getPrice()->getCalculatedTaxes()->getAmount() / $lineItem->getQuantity()) * 100);
                    }

                    if ($invoiceEntity != null) {
                        $response = $this->monduClient->createCreditNote(
                            $invoiceEntity->getExternalInvoiceUuid(),
                            [
                                'external_reference_id' => $creditNoteNumber,
                                'gross_amount_cents' => $grossAmountCents,
                                'tax_cents' => $taxCents
                            ]
                        );

                        if ($response == null) {
                            $this->log('Credit Credit Note Response Failed', [$event]);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->log('Create Credit Note Failed', [$event], $e);
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
            $message . '. (Exception: ' . $exceptionMessage . ')',
            $data
        );

        throw new MonduException('Creating credit note failed. Please contact Mondu Support.');
    }
}
