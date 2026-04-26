<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Subscriber;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Document\Event\CreditNoteOrdersEvent;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Backports a Shopware 6.7 core fix: in 6.6, CreditNoteRenderer includes ALL credit
 * line items in every credit note document. We filter out items that were added BEFORE
 * the latest existing credit note for the same invoice, so only new items remain.
 */
class CreditNoteDocumentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CreditNoteOrdersEvent::class => 'onCreditNoteOrders',
        ];
    }

    public function onCreditNoteOrders(CreditNoteOrdersEvent $event): void
    {
        foreach ($event->getOrders() as $order) {
            try {
                $operation = $event->getOperations()[$order->getId()] ?? null;
                if ($operation === null) {
                    continue;
                }

                $referencedDocumentId = $operation->getReferencedDocumentId();
                if ($referencedDocumentId === null || $referencedDocumentId === '') {
                    continue;
                }

                // Find when the latest previous credit note for this invoice was created.
                // Credit items added BEFORE that timestamp belong to previous CNs.
                $latestCNCreatedAt = $this->getLatestCreditNoteCreatedAt($referencedDocumentId);

                if ($latestCNCreatedAt === null) {
                    // First credit note for this invoice — nothing to filter
                    continue;
                }

                $lineItems = $order->getLineItems();
                if ($lineItems === null) {
                    continue;
                }

                $toRemove = [];
                $remaining = 0;
                foreach ($lineItems as $item) {
                    if ($item->getType() !== LineItem::CREDIT_LINE_ITEM_TYPE) {
                        continue;
                    }

                    if ($item->getCreatedAt() !== null && $item->getCreatedAt() <= $latestCNCreatedAt) {
                        $toRemove[] = $item->getId();
                    } else {
                        $remaining++;
                    }
                }

                if (empty($toRemove) || $remaining === 0) {
                    continue;
                }

                foreach ($toRemove as $id) {
                    $lineItems->remove($id);
                }

                $this->logger->info('mondu.INFO: CreditNoteDocumentSubscriber: filtered previous credit items', [
                    'order_id' => $order->getId(),
                    'removed' => count($toRemove),
                    'remaining' => $remaining,
                    'cutoff' => $latestCNCreatedAt->format('Y-m-d H:i:s'),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('mondu.WARNING: CreditNoteDocumentSubscriber failed, using default behavior', [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get the created_at of the most recent credit_note document referencing the given invoice.
     */
    private function getLatestCreditNoteCreatedAt(string $referencedDocumentId): ?\DateTimeImmutable
    {
        $sql = '
            SELECT d.created_at
            FROM document AS d
            INNER JOIN document_type AS dt ON dt.id = d.document_type_id
            WHERE d.referenced_document_id = :referencedDocumentId
              AND dt.technical_name = :technicalName
            ORDER BY d.created_at DESC
            LIMIT 1
        ';

        $result = $this->connection->fetchOne($sql, [
            'referencedDocumentId' => Uuid::fromHexToBytes($referencedDocumentId),
            'technicalName' => 'credit_note',
        ]);

        if ($result === false || $result === null) {
            return null;
        }

        return new \DateTimeImmutable($result);
    }
}
