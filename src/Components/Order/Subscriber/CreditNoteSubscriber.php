<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Subscriber;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Util\CriteriaHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\DeleteCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\Validation\WriteConstraintViolationException;
use Shopware\Core\Checkout\Document\DocumentDefinition;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
use Mondu\MonduPayment\Components\Invoice\InvoiceDataEntity;

class CreditNoteSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderDataRepository,
        private readonly EntityRepository $invoiceDataRepository,
        private readonly MonduClient $monduClient,
        private readonly LoggerInterface $logger,
        private readonly ConfigService $configService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'document.written' => 'onDocumentWritten',
            PreWriteValidationEvent::class => 'onPreDelete',
        ];
    }

    public function onPreDelete(PreWriteValidationEvent $event): void
    {
        $documentIds = [];
        foreach ($event->getCommands() as $command) {
            if ($command instanceof DeleteCommand && $command->getEntityName() === DocumentDefinition::ENTITY_NAME) {
                $documentIds[] = bin2hex($command->getPrimaryKey()['id']);
            }
        }

        if (empty($documentIds)) {
            return;
        }

        try {
            $liveContext = Context::createDefaultContext();

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsAnyFilter('documentId', $documentIds));
            $entries = $this->invoiceDataRepository->search($criteria, $liveContext);

            if ($entries->getTotal() === 0) {
                return;
            }

            foreach ($entries as $entry) {
                if ($entry->getExternalInvoiceUuid() === null) {
                    continue;
                }

                $parentCriteria = new Criteria();
                $parentCriteria->addFilter(new EqualsFilter('orderId', $entry->getOrderId()));
                $parentCriteria->addFilter(
                    new NotFilter(NotFilter::CONNECTION_AND, [
                        new EqualsAnyFilter('documentId', $documentIds)
                    ])
                );
                $parentInvoice = $this->invoiceDataRepository->search($parentCriteria, $liveContext)->first();

                if ($parentInvoice === null) {
                    continue;
                }

                $order = $this->getOrder($entry->getOrderId(), $liveContext);
                if ($order === null) {
                    continue;
                }

                $this->monduClient->setSalesChannelId($order->getSalesChannelId())->cancelCreditNote(
                    $parentInvoice->getExternalInvoiceUuid(),
                    $entry->getExternalInvoiceUuid()
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('mondu.ERROR: Failed to cancel credit note on document delete: ' . $e->getMessage());

            $violations = new ConstraintViolationList([
                new ConstraintViolation(
                    'Cannot delete document: failed to cancel credit note at Mondu (' . $e->getMessage() . ')',
                    '',
                    [],
                    null,
                    '/documentId',
                    null
                ),
            ]);
            $event->getExceptions()->add(new WriteConstraintViolationException($violations));
        }
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

                    $referencedDocumentId = $payload['referencedDocumentId'] ?? null;

                    $invoiceCriteria = new Criteria();
                    $invoiceCriteria->addFilter(new EqualsFilter('orderId', $orderId));
                    if ($referencedDocumentId !== null) {
                        $invoiceCriteria->addFilter(new EqualsFilter('documentId', $referencedDocumentId));
                    } else {
                        $invoiceCriteria->addFilter(new EqualsFilter('invoiceNumber', $invoiceNumber));
                    }
                    $invoiceEntity = $this->invoiceDataRepository->search($invoiceCriteria, $event->getContext())->first();

                    if ($invoiceEntity === null) {
                        $this->logger->warning('mondu.WARNING: Parent invoice not found for credit note, skipping Mondu API call', [
                            'order_id' => $orderId,
                            'invoice_number' => $invoiceNumber,
                            'credit_note_number' => $creditNoteNumber,
                        ]);
                        return;
                    }

                    // Find the most recently processed credit note for this order.
                    // Credit note entries have invoiceNumber = their own CN number (≠ parent invoice number).
                    // We use its createdAt as a cutoff so that only credit items added AFTER
                    // that point are counted — i.e. only the items belonging to THIS credit note.
                    // Exclude credit notes whose Shopware document has been detached from the
                    // parent invoice (referencedDocumentId=NULL) — those are cancelled-at-Mondu
                    // entries, and their items must be re-counted for the new CN.
                    $prevCNCriteria = new Criteria();
                    $prevCNCriteria->addAssociation('document');
                    $prevCNCriteria->addFilter(new EqualsFilter('orderId', $orderId));
                    $prevCNCriteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [new EqualsFilter('invoiceNumber', $invoiceNumber)]));
                    $prevCNCriteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
                    $latestPrevCN = null;
                    foreach ($this->invoiceDataRepository->search($prevCNCriteria, $event->getContext())->getEntities() as $candidate) {
                        if ($candidate->getDocument() !== null && $candidate->getDocument()->getReferencedDocumentId() !== null) {
                            $latestPrevCN = $candidate;
                            break;
                        }
                    }

                    $order = $this->getOrder($orderId, $event->getContext());

                    $grossAmountCents = 0;
                    $taxCents = 0;
                    foreach ($order->getLineItems() as $lineItem) {
                        if ($lineItem->getType() !== \Shopware\Core\Checkout\Cart\LineItem\LineItem::CREDIT_LINE_ITEM_TYPE) {
                            continue;
                        }

                        // Skip credit items that were already covered by a previous credit note
                        if ($latestPrevCN !== null && $lineItem->getCreatedAt() <= $latestPrevCN->getCreatedAt()) {
                            continue;
                        }

                        $grossAmountCents += round(abs($lineItem->getPrice()->getTotalPrice()) * 100);
                        $taxCents += round(abs($lineItem->getPrice()->getCalculatedTaxes()->getAmount() / $lineItem->getQuantity()) * 100);
                    }

                    if ($grossAmountCents <= 0) {
                        return;
                    }

                    $response = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->createCreditNote(
                        $invoiceEntity->getExternalInvoiceUuid(),
                        [
                            'external_reference_id' => $creditNoteNumber,
                            'gross_amount_cents' => $grossAmountCents,
                            'tax_cents' => $taxCents
                        ]
                    );

                    if (is_array($response) && ($response['status'] ?? null) === 'invoice_cancelled') {
                        $this->logger->warning('mondu.WARNING: Cannot create credit note — parent invoice is cancelled at Mondu', [
                            'order_id' => $orderId,
                            'invoice_number' => $invoiceNumber,
                            'credit_note_number' => $creditNoteNumber,
                            'message' => $response['message'] ?? '',
                        ]);
                        throw new MonduException('Credit note cannot be created because the parent invoice has been cancelled at Mondu. Please cancel the Shopware credit note document and use a different invoice.');
                    }

                    if ($response == null || !isset($response['credit_note']['uuid'])) {
                        $this->log('Credit Credit Note Response Failed', [$event]);
                    } else {
                        $this->invoiceDataRepository->upsert([
                            [
                                InvoiceDataEntity::FIELD_ORDER_ID => $order->getId(),
                                InvoiceDataEntity::FIELD_ORDER_VERSION_ID => $order->getVersionId(),
                                InvoiceDataEntity::FIELD_DOCUMENT_ID => $payload['id'],
                                InvoiceDataEntity::FIELD_INVOICE_NUMBER => $creditNoteNumber,
                                InvoiceDataEntity::FIELD_EXTERNAL_INVOICE_UUID => $response['credit_note']['uuid'],
                            ]
                        ], $event->getContext());
                    }
                }
            }
        } catch (MonduException $e) {
            // preserve the specific message (e.g. "parent invoice cancelled at Mondu")
            $this->logger->critical('mondu.CRITICAL: Create Credit Note Failed. (Exception: ' . $e->getMessage() . ')', [$event]);
            throw $e;
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
            'mondu.CRITICAL: ' . $message . '. (Exception: ' . $exceptionMessage . ')',
            $data
        );

        throw new MonduException('Creating credit note failed. Please contact Mondu Support.');
    }
}
