<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Controller;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

#[Route(defaults: ['_routeScope' => ['api']])]
class CreditNoteController extends AbstractController
{
    public function __construct(
        private readonly MonduClient $monduClient,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $invoiceDataRepository,
        private readonly EntityRepository $orderDataRepository,
        private readonly EntityRepository $documentRepository
    ) {}

    #[Route(path: '/api/mondu/orders/{orderId}/credit_notes/{creditNoteId}/cancel', name: 'mondu-payment.credit_note.cancel', methods: ['POST'])]
    public function cancel(Request $request, string $orderId, string $creditNoteId, Context $context): Response
    {
        try {
            $liveContext = Context::createDefaultContext();

            $creditNoteCriteria = new Criteria();
            $creditNoteCriteria->addFilter(new EqualsFilter('documentId', $creditNoteId));
            $creditNoteEntity = $this->invoiceDataRepository->search($creditNoteCriteria, $context)->first();
            if ($creditNoteEntity === null) {
                $creditNoteEntity = $this->invoiceDataRepository->search($creditNoteCriteria, $liveContext)->first();
            }

            if ($creditNoteEntity === null) {
                return new Response(json_encode(['status' => 'credit_note_not_registered_in_mondu', 'error' => '2']), Response::HTTP_BAD_REQUEST);
            }

            $documentCriteria = new Criteria();
            $documentCriteria->addFilter(new EqualsFilter('id', $creditNoteId));
            $documentCriteria->addAssociation('order');
            $document = $this->documentRepository->search($documentCriteria, $context)->first();
            if ($document === null) {
                $document = $this->documentRepository->search($documentCriteria, $liveContext)->first();
            }

            if ($document === null) {
                return new Response(json_encode(['status' => 'document_not_found', 'error' => '2']), Response::HTTP_BAD_REQUEST);
            }

            $documentInvoiceNumber = $document->getConfig()['custom']['invoiceNumber'] ?? null;

            if ($documentInvoiceNumber === null) {
                return new Response(json_encode(['status' => 'invoice_number_missing', 'error' => '2']), Response::HTTP_BAD_REQUEST);
            }

            $invoiceCriteria = new Criteria();
            $invoiceCriteria->addFilter(new EqualsFilter('invoiceNumber', $documentInvoiceNumber));
            $invoiceCriteria->addFilter(new EqualsFilter('orderId', $orderId));
            $invoiceEntity = $this->invoiceDataRepository->search($invoiceCriteria, $context)->first();
            if ($invoiceEntity === null) {
                $invoiceEntity = $this->invoiceDataRepository->search($invoiceCriteria, $liveContext)->first();
            }

            if ($invoiceEntity === null) {
                return new Response(json_encode(['status' => 'invoice_not_registered_in_mondu', 'error' => '2']), Response::HTTP_BAD_REQUEST);
            }

            $cancellation = $this->monduClient->setSalesChannelId($document->getOrder()->getSalesChannelId())->cancelCreditNote(
                $invoiceEntity->getExternalInvoiceUuid(),
                $creditNoteEntity->getExternalInvoiceUuid()
            );

            $status = is_array($cancellation) ? ($cancellation['status'] ?? null) : null;

            if ($status === 'already_cancelled') {
                $this->unlinkCancelledCreditNote($creditNoteId, $context);
                return new Response(json_encode(['status' => 'already_cancelled', 'error' => '4']), Response::HTTP_BAD_REQUEST);
            }

            if ($status === 'not_found') {
                $this->unlinkCancelledCreditNote($creditNoteId, $context);
                return new Response(json_encode(['status' => 'not_found_in_mondu', 'error' => '2']), Response::HTTP_BAD_REQUEST);
            }

            if ($cancellation !== null) {
                $this->unlinkCancelledCreditNote($creditNoteId, $context);
                return new Response(json_encode(['status' => 'ok', 'error' => '0']), Response::HTTP_OK);
            }

            return new Response(json_encode(['status' => 'request_failed', 'error' => '1' ]), Response::HTTP_BAD_REQUEST);
        } catch (\Exception) {
            return new Response(json_encode(['status' => 'error', 'error' => '3' ]), Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * After a credit note is cancelled at Mondu, detach it from its parent invoice in
     * Shopware so that Shopware's CreditNoteRenderer no longer counts its credit line
     * items as "processed" (Shopware considers every credit line item on an order
     * processed as soon as ANY credit_note/zugferd_(embedded_)credit_note document
     * references the parent invoice). Without this the merchant cannot create a new
     * credit note after cancelling all existing ones.
     */
    private function unlinkCancelledCreditNote(string $creditNoteDocumentId, Context $context): void
    {
        try {
            $this->documentRepository->update([
                [
                    'id' => $creditNoteDocumentId,
                    'referencedDocumentId' => null,
                    'customFields' => [
                        'mondu_cancelled_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    ],
                ],
            ], $context);
        } catch (\Throwable) {
            // non-fatal: the cancel at Mondu already succeeded / already was cancelled
        }
    }
}
