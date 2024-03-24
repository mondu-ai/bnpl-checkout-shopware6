<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Subscriber;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Util\CriteriaHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
use Mondu\MonduPayment\Components\Invoice\InvoiceDataEntity;

class CreditNoteSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $invoiceDataRepository,
        private readonly MonduClient $monduClient,
        private readonly LoggerInterface $logger
    ) {}

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

                if (!isset($payload['config']['custom']['creditNoteNumber'])){
                    return;
                }

                if ($payload['config']['name'] == 'credit_note') {
                    $creditNoteNumber = $payload['config']['custom']['creditNoteNumber'];

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
                        $response = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->createCreditNote(
                            $invoiceEntity->getExternalInvoiceUuid(),
                            [
                                'external_reference_id' => $creditNoteNumber,
                                'gross_amount_cents' => $grossAmountCents,
                                'tax_cents' => $taxCents
                            ]
                        );

                        if ($response == null) {
                            $this->log('Credit Credit Note Response Failed', [$event]);
                        } else {

                            $this->invoiceDataRepository->upsert([
                                [
                                    InvoiceDataEntity::FIELD_ORDER_ID => $order->getId(),
                                    InvoiceDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                                    InvoiceDataEntity::FIELD_DOCUMENT_ID => $payload['id'],
                                    InvoiceDataEntity::FIELD_INVOICE_NUMBER => $creditNoteNumber,
                                    InvoiceDataEntity::FIELD_EXTERNAL_INVOICE_UUID => $response['credit_note']['uuid']                                    ,
                                ]
                              ], $event->getContext());

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
