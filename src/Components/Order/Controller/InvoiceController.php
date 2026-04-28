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
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Util\CriteriaHelper;

#[Route(defaults: ['_routeScope' => ['api']])]
class InvoiceController extends AbstractController
{
    public function __construct(
        private readonly MonduClient $monduClient,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $invoiceDataRepository,
        private readonly EntityRepository $orderDataRepository
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
                return new Response(json_encode(['status' => 'already_cancelled', 'error' => '0']), Response::HTTP_OK);
            }

            if ($orderEntity != null && $invoiceEntity != null) {
                $cancellation = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->cancelInvoice(
                    $orderEntity->getReferenceId(),
                    $invoiceEntity->getExternalInvoiceUuid()
                );

                if ($cancellation != null) {
                    $this->deleteAllInvoiceData($orderId, $context);
                    $this->resetOrderStateToAuthorized($orderId, $context);
                    return new Response(json_encode(['status' => 'ok', 'error' => '0']), Response::HTTP_OK);
                }

                return new Response(json_encode(['status' => 'request_failed', 'error' => '1' ]), Response::HTTP_BAD_REQUEST);
            }

            return new Response(json_encode(['status' => 'not_found', 'error' => '2' ]), Response::HTTP_BAD_REQUEST);
        } catch (\Exception) {
            return new Response(json_encode(['status' => 'error', 'error' => '3' ]), Response::HTTP_BAD_REQUEST);
        }
    }

    private function deleteAllInvoiceData(string $orderId, Context $context): void
    {
        $liveContext = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));

        $liveInvoices = $this->invoiceDataRepository->search($criteria, $liveContext);
        foreach ($liveInvoices as $invoice) {
            $this->invoiceDataRepository->delete([['id' => $invoice->getId()]], $liveContext);
        }

        $versionedInvoices = $this->invoiceDataRepository->search($criteria, $context);
        foreach ($versionedInvoices as $invoice) {
            $this->invoiceDataRepository->delete([['id' => $invoice->getId()]], $context);
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

    protected function getOrder(string $orderId, Context $context)
    {
        $criteria = CriteriaHelper::getCriteriaForOrder($orderId);

        return $this->orderRepository->search($criteria, $context)->first();
    }
}
