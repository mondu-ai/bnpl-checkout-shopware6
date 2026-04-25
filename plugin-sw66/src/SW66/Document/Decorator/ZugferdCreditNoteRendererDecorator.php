<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\SW66\Document\Decorator;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Document\Renderer\AbstractDocumentRenderer;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererConfig;
use Shopware\Core\Checkout\Document\Renderer\RendererResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Workaround for a Shopware 6.6 Core inconsistency in ZUGFeRD-credit-note
 * generation.
 *
 * Context
 * -------
 * In SW6.6 the base `CreditNoteRenderer` does NOT filter out "already credited"
 * line items — it just takes every credit line item and generates a PDF. The
 * `ZugferdCreditNoteRenderer`, however, DOES filter them: it computes
 *
 *     $creditItems = $liveCreditItems
 *                  - $invoiceCreditIds
 *                  - $creditNoteItemIds;   // from getPreviouslyCreditedIdsForInvoice
 *
 * `getPreviouslyCreditedIdsForInvoice` joins `document` → `order_line_item` on
 * `order_version_id` for **every** document whose technical_name is in
 * (`credit_note`, `zugferd_credit_note`, `zugferd_embedded_credit_note`). So as
 * soon as a merchant has issued a normal `credit_note` once, every subsequent
 * ZUGFeRD credit note on the same invoice is rejected with
 * "no unprocessed credit line items exists", and the embedded-renderer then
 * surfaces that as `"Zugferd document is null"`.
 *
 * Shopware 6.7 partially addresses this via
 * `OrderDocumentCriteriaFactory::create` filtering `documents` by the target
 * technical name when feature flag `v6.7.0.0` is active. On 6.6 there is no such
 * filter.
 *
 * The fix
 * -------
 * Temporarily detach (`referenced_document_id = NULL`) every `credit_note`
 * document (the plain, non-ZUGFeRD type) that points at the invoices involved
 * in this render pass, so that Core's SQL join in
 * `getPreviouslyCreditedIdsForInvoice` no longer picks them up. The inner
 * renderer then correctly sees the remaining credit items as "unprocessed" and
 * generates the ZUGFeRD XML. After the call we restore every touched row to
 * its previous `referenced_document_id`.
 *
 * The DB mutation is wrapped in try/finally so an exception in the inner
 * renderer still restores the state. The window where rows are in the NULL
 * state is the inner `render()` call — no HTTP boundary, no awaited I/O to
 * external systems, so concurrent reads hitting that exact window are extremely
 * unlikely in practice.
 */
class ZugferdCreditNoteRendererDecorator extends AbstractDocumentRenderer
{
    public function __construct(
        private readonly AbstractDocumentRenderer $inner,
        private readonly Connection $connection,
        private readonly ConfigService $configService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(): string
    {
        return $this->inner->supports();
    }

    public function getDecorated(): AbstractDocumentRenderer
    {
        return $this->inner;
    }

    public function render(array $operations, Context $context, DocumentRendererConfig $rendererConfig): RendererResult
    {
        $invoiceIds = [];
        foreach ($operations as $operation) {
            $referencedId = $operation->getReferencedDocumentId();
            if (is_string($referencedId) && $referencedId !== '') {
                $invoiceIds[$referencedId] = true;
            }
        }

        if ($invoiceIds === []) {
            return $this->inner->render($operations, $context, $rendererConfig);
        }

        // Snapshot and null out non-ZUGFeRD credit_note documents pointing at
        // those invoices. Returns id → prior referenced_document_id hex (we
        // need to restore exactly what was there, not assume a fresh value).
        $snapshot = $this->detachPlainCreditNotes(array_keys($invoiceIds));

        try {
            return $this->inner->render($operations, $context, $rendererConfig);
        } finally {
            $this->restore($snapshot);
        }
    }

    /**
     * @param list<string> $invoiceIdHexes
     *
     * @return array<string, string> docIdHex => prior referencedDocumentIdHex
     */
    private function detachPlainCreditNotes(array $invoiceIdHexes): array
    {
        try {
            $binaryInvoices = array_map(
                static fn (string $hex): string => Uuid::fromHexToBytes($hex),
                $invoiceIdHexes
            );

            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(d.id)) AS doc_id, LOWER(HEX(d.referenced_document_id)) AS ref_id
                 FROM `document` d
                 INNER JOIN `document_type` dt ON dt.id = d.document_type_id
                 WHERE dt.technical_name = :technicalName
                   AND d.referenced_document_id IN (:referencedIds)',
                [
                    'technicalName' => 'credit_note',
                    'referencedIds' => $binaryInvoices,
                ],
                [
                    'referencedIds' => ArrayParameterType::STRING,
                ]
            );

            if ($rows === []) {
                return [];
            }

            $snapshot = [];
            $idsToNull = [];
            foreach ($rows as $row) {
                $snapshot[$row['doc_id']] = $row['ref_id'];
                $idsToNull[] = Uuid::fromHexToBytes($row['doc_id']);
            }

            $this->connection->executeStatement(
                'UPDATE `document` SET referenced_document_id = NULL WHERE id IN (:ids)',
                ['ids' => $idsToNull],
                ['ids' => ArrayParameterType::STRING]
            );

            if ($this->configService->isExtendedLogsEnabled()) {
                $this->logger->info('mondu.INFO: ZUGFeRD CN decorator detached plain credit_note documents', [
                    'invoice_ids' => $invoiceIdHexes,
                    'detached' => array_keys($snapshot),
                ]);
            }

            return $snapshot;
        } catch (\Throwable $e) {
            // Detachment failed — inner renderer will run against unchanged DB.
            // Don't mask the original ZUGFeRD error with a DB error.
            $this->logger->warning('mondu.WARNING: ZUGFeRD CN decorator could not detach plain credit_note documents', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param array<string, string> $snapshot docIdHex => referencedDocumentIdHex
     */
    private function restore(array $snapshot): void
    {
        if ($snapshot === []) {
            return;
        }

        foreach ($snapshot as $docIdHex => $refIdHex) {
            try {
                $this->connection->executeStatement(
                    'UPDATE `document` SET referenced_document_id = :ref WHERE id = :id',
                    [
                        'id' => Uuid::fromHexToBytes($docIdHex),
                        'ref' => Uuid::fromHexToBytes($refIdHex),
                    ]
                );
            } catch (\Throwable $e) {
                $this->logger->error('mondu.ERROR: ZUGFeRD CN decorator FAILED to restore referenced_document_id', [
                    'document_id' => $docIdHex,
                    'original_ref' => $refIdHex,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
