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
            $creditNoteCriteria = new Criteria();
            $creditNoteCriteria->addFilter(new EqualsFilter('documentId', $creditNoteId));
            $creditNoteEntity = $this->invoiceDataRepository->search($creditNoteCriteria, $context)->first();
            
            $documentCriteria = new Criteria();
            $documentCriteria->addFilter(new EqualsFilter('id', $creditNoteId));
            $documentCriteria->addAssociation('order');
            $document = $this->documentRepository->search($documentCriteria, $context)->first();
            $documentInvoiceNumber = $document->getConfig()['custom']['invoiceNumber'];

            $invoiceCriteria = new Criteria();
            $invoiceCriteria->addFilter(new EqualsFilter('invoiceNumber', $documentInvoiceNumber));
            $invoiceEntity = $this->invoiceDataRepository->search($invoiceCriteria, $context)->first();

            if ($invoiceEntity != null) {
                $cancellation = $this->monduClient->setSalesChannelId($document->getOrder()->getSalesChannelId())->cancelCreditNote(
                    $invoiceEntity->getExternalInvoiceUuid(),
                    $creditNoteEntity->getExternalInvoiceUuid()
                );

                if ($cancellation != null) {
                    return new Response(json_encode(['status' => 'ok', 'error' => '0']), Response::HTTP_OK);
                }

                return new Response(json_encode(['status' => 'request_failed', 'error' => '1' ]), Response::HTTP_BAD_REQUEST);
            }

            return new Response(json_encode(['status' => 'not_found', 'error' => '2' ]), Response::HTTP_BAD_REQUEST);
        } catch (\Exception) {
            return new Response(json_encode(['status' => 'error', 'error' => '3' ]), Response::HTTP_BAD_REQUEST);
        }
    }
}
