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
use Mondu\MonduPayment\Components\Order\Model\Extension\OrderExtension;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Util\CriteriaHelper;

/**
 * @Route(defaults={"_routeScope"={"api"}})
 */
class InvoiceController extends AbstractController
{
    private MonduClient $monduClient;
    private EntityRepository $orderRepository;
    private EntityRepository $invoiceDataRepository;
    private EntityRepository $orderDataRepository;


    public function __construct(
        MonduClient $monduClient,
        EntityRepository $orderRepository,
        EntityRepository $invoiceDataRepository,
        EntityRepository $orderDataRepository
    ) {
        $this->monduClient = $monduClient;
        $this->orderRepository = $orderRepository;
        $this->invoiceDataRepository = $invoiceDataRepository;
        $this->orderDataRepository = $orderDataRepository;
    }

    /**
     * @Route(name="mondu-payment.invoice.cancel", path="/api/mondu/orders/{orderId}/{invoiceId}/cancel", defaults={"csrf_protected"=false}, methods={"POST"})
     */
    public function cancel(Request $request, string $orderId, string $invoiceId, Context $context): Response
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $orderId));

            $invoiceCriteria = new Criteria();
            $order = $this->getOrder($orderId, $context);
            $invoiceCriteria->addFilter(new EqualsFilter('documentId', $invoiceId));

            $orderEntity = $this->orderDataRepository->search($criteria, $context)->first();
            $invoiceEntity = $this->invoiceDataRepository->search($invoiceCriteria, $context)->first();

            if ($orderEntity != null && $invoiceEntity != null) {
                $cancelation = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->cancelInvoice(
                    $orderEntity->getReferenceId(),
                    $invoiceEntity->getExternalInvoiceUuid()
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

    protected function getOrder(string $orderId, Context $context)
    {
        $criteria = CriteriaHelper::getCriteriaForOrder($orderId);

        return $this->orderRepository->search($criteria, $context)->first();
    }
}
