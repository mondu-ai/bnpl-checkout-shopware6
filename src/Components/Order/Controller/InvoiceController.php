<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Controller;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\Invoice\InvoiceDataEntity;
use Mondu\MonduPayment\Util\CriteriaHelper;

#[Route(defaults: ['_routeScope' => ['api']])]
class InvoiceController extends AbstractController
{
    public function __construct(
        private readonly MonduClient $monduClient,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $invoiceDataRepository,
        private readonly EntityRepository $orderDataRepository,
        private readonly EntityRepository $documentRepository,
        private readonly DocumentGenerator $documentGenerator
    ) {}

    #[Route(path: '/api/mondu/orders/{orderId}/{invoiceId}/cancel', name: 'mondu-payment.invoice.cancel', methods: ['POST'])]
    public function cancel(Request $request, string $orderId, string $invoiceId, Context $context): Response
    {
        try {
            $liveContext = Context::createDefaultContext();

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $orderId));

            $invoiceCriteria = new Criteria();
            $order = $this->getOrder($orderId, $context);
            $invoiceCriteria->addFilter(new EqualsFilter('documentId', $invoiceId));

            $orderEntity = $this->orderDataRepository->search($criteria, $context)->first();
            // Search in both versioned and live context to find the invoice
            $invoiceEntity = $this->invoiceDataRepository->search($invoiceCriteria, $context)->first();
            if ($invoiceEntity === null) {
                $invoiceEntity = $this->invoiceDataRepository->search($invoiceCriteria, $liveContext)->first();
            }

            if ($orderEntity != null && $invoiceEntity === null) {
                return new JsonResponse(['status' => 'ok', 'error' => '0']);
            }

            if ($orderEntity != null && $invoiceEntity != null) {
                if ($invoiceEntity->getInvoiceState() === 'cancelled') {
                    return new JsonResponse(['status' => 'already_cancelled', 'error' => '0']);
                }

                $cancellation = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->cancelInvoice(
                    $orderEntity->getReferenceId(),
                    $invoiceEntity->getExternalInvoiceUuid()
                );

                if ($cancellation != null) {
                    $this->markInvoiceDataAsCancelled($invoiceId, $context);
                    $this->syncCreditNoteStatesFromResponse($cancellation, $orderId, $liveContext);
                    $this->resetOrderStateToAuthorized($orderId, $context);
                    $this->createStornoDocument($orderId, $invoiceId, $liveContext);
                    return new JsonResponse(['status' => 'ok', 'error' => '0']);
                }

                return new JsonResponse(['status' => 'request_failed', 'error' => '1'], Response::HTTP_BAD_REQUEST);
            }

            return new JsonResponse(['status' => 'not_found', 'error' => '2'], Response::HTTP_BAD_REQUEST);
        } catch (\Exception) {
            return new JsonResponse(['status' => 'error', 'error' => '3'], Response::HTTP_BAD_REQUEST);
        }
    }

    private function markInvoiceDataAsCancelled(string $documentId, Context $context): void
    {
        $liveContext = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('documentId', $documentId));

        $invoice = $this->invoiceDataRepository->search($criteria, $liveContext)->first();
        if ($invoice !== null) {
            $this->invoiceDataRepository->update([[
                'id' => $invoice->getId(),
                InvoiceDataEntity::FIELD_INVOICE_STATE => 'cancelled',
            ]], $liveContext);
        }

        $versionedInvoice = $this->invoiceDataRepository->search($criteria, $context)->first();
        if ($versionedInvoice !== null && $versionedInvoice->getInvoiceState() !== 'cancelled') {
            $this->invoiceDataRepository->update([[
                'id' => $versionedInvoice->getId(),
                InvoiceDataEntity::FIELD_INVOICE_STATE => 'cancelled',
            ]], $context);
        }
    }

    private function syncCreditNoteStatesFromResponse(array $response, string $orderId, Context $context): void
    {
        $creditNotes = $response['invoice']['credit_notes'] ?? [];

        foreach ($creditNotes as $cn) {
            if (($cn['state'] ?? '') !== 'canceled') {
                continue;
            }

            $uuid = $cn['uuid'] ?? null;
            if ($uuid === null) {
                continue;
            }

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('externalInvoiceUuid', $uuid));
            $criteria->addFilter(new EqualsFilter('orderId', $orderId));
            $entity = $this->invoiceDataRepository->search($criteria, $context)->first();

            if ($entity === null) {
                continue;
            }

            if ($entity->getInvoiceState() !== 'cancelled') {
                $this->invoiceDataRepository->update([[
                    'id' => $entity->getId(),
                    InvoiceDataEntity::FIELD_INVOICE_STATE => 'cancelled',
                ]], $context);
            }

            $this->unlinkDocument($entity->getDocumentId(), $context);
        }
    }

    private function unlinkDocument(string $documentId, Context $context): void
    {
        try {
            $this->documentRepository->update([[
                'id' => $documentId,
                'referencedDocumentId' => null,
            ]], $context);
        } catch (\Throwable) {
        }
    }

    private function resetOrderStateToAuthorized(string $orderId, Context $context): void
    {
        $liveContext = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));

        $allOrderData = $this->orderDataRepository->search($criteria, $liveContext);

        foreach ($allOrderData as $orderData) {
            $this->orderDataRepository->update([[
                'id' => $orderData->getId(),
                OrderDataEntity::FIELD_ORDER_STATE => 'authorized',
            ]], $liveContext);
        }

        $versionedOrderData = $this->orderDataRepository->search($criteria, $context);

        foreach ($versionedOrderData as $orderData) {
            if ($orderData->getOrderState() !== 'authorized') {
                $this->orderDataRepository->update([[
                    'id' => $orderData->getId(),
                    OrderDataEntity::FIELD_ORDER_STATE => 'authorized',
                ]], $context);
            }
        }
    }

    private function createStornoDocument(string $orderId, string $invoiceDocumentId, Context $context): void
    {
        try {
            $operation = new DocumentGenerateOperation(
                $orderId,
                'pdf',
                [],
                $invoiceDocumentId
            );

            $this->documentGenerator->generate('storno', [$orderId => $operation], $context);
        } catch (\Throwable) {
        }
    }

    #[Route(path: '/api/mondu/orders/{orderId}/document-statuses', name: 'mondu-payment.order.document-statuses', methods: ['GET'])]
    public function documentStatuses(string $orderId, Context $context): JsonResponse
    {
        $liveContext = Context::createDefaultContext();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));

        $invoices = $this->invoiceDataRepository->search($criteria, $liveContext);

        $statuses = [];
        foreach ($invoices as $invoice) {
            $state = $invoice->getInvoiceState();
            $statuses[$invoice->getDocumentId()] = $state === 'cancelled' ? 'cancelled' : 'sent';
        }

        return new JsonResponse($statuses);
    }

    protected function getOrder(string $orderId, Context $context)
    {
        $criteria = CriteriaHelper::getCriteriaForOrder($orderId);

        return $this->orderRepository->search($criteria, $context)->first();
    }
}
