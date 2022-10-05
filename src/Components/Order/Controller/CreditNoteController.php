<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Controller;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Util\CriteriaHelper;
use Shopware\Core\Checkout\Order\OrderEntity;

/**
 * @RouteScope(scopes={"api"})
 */
class CreditNoteController extends AbstractController
{
    private MonduClient $monduClient;
    private EntityRepositoryInterface $orderRepository;
    private EntityRepositoryInterface $invoiceDataRepository;
    private EntityRepositoryInterface $orderDataRepository;
    private EntityRepositoryInterface $documentRepository;


    public function __construct(
        MonduClient $monduClient,
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $invoiceDataRepository,
        EntityRepositoryInterface $orderDataRepository,
        EntityRepositoryInterface $documentRepository
    ) {
        $this->monduClient = $monduClient;
        $this->orderRepository = $orderRepository;
        $this->invoiceDataRepository = $invoiceDataRepository;
        $this->orderDataRepository = $orderDataRepository;
        $this->documentRepository = $documentRepository;
    }

    /**
     * @Route(name="mondu-payment.credit_note.cancel", path="/api/mondu/orders/{orderId}/credit_notes/{creditNoteId}/cancel", defaults={"csrf_protected"=false}, methods={"POST"})
     */
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
                $cancelation = $this->monduClient->setSalesChannelId($document->getOrder()->getSalesChannelId())->cancelCreditNote(
                    $invoiceEntity->getExternalInvoiceUuid(),
                    $creditNoteEntity->getExternalInvoiceUuid()
                );

                if ($cancelation != null) {
                    return new Response(json_encode(['status' => 'ok', 'error' => '0']), Response::HTTP_OK);
                }

                return new Response(json_encode(['status' => 'request_failed', 'error' => '1' ]), Response::HTTP_BAD_REQUEST);
            }

            return new Response(json_encode(['status' => 'not_found', 'error' => '2' ]), Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return new Response(json_encode(['status' => 'error', 'error' => '3' ]), Response::HTTP_BAD_REQUEST);
        }
    }
}
