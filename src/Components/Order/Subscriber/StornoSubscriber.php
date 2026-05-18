<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Order\Subscriber;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\Invoice\InvoiceDataEntity;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Util\CriteriaHelper;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class StornoSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderDataRepository,
        private readonly EntityRepository $invoiceDataRepository,
        private readonly EntityRepository $documentRepository,
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
            $writeResults = $event->getWriteResults();
            if (count($writeResults) === 0) {
                return;
            }

            $payload = $writeResults[0]->getPayload();
            $configName = $payload['config']['name'] ?? '';

            if (!in_array($configName, ['storno', 'cancellation_invoice', 'zugferd_cancellation_invoice', 'zugferd_embedded_cancellation_invoice'], true)) {
                return;
            }

            $referencedDocumentId = $payload['referencedDocumentId'] ?? null;
            if ($referencedDocumentId === null) {
                return;
            }

            $orderId = $payload['orderId'];
            $liveContext = Context::createDefaultContext();

            $invoiceCriteria = new Criteria();
            $invoiceCriteria->addFilter(new EqualsFilter('documentId', $referencedDocumentId));
            $invoiceEntity = $this->invoiceDataRepository->search($invoiceCriteria, $liveContext)->first();

            if ($invoiceEntity === null) {
                return;
            }

            if ($invoiceEntity->getInvoiceState() === 'cancelled') {
                return;
            }

            $orderDataCriteria = new Criteria();
            $orderDataCriteria->addFilter(new EqualsFilter('orderId', $orderId));
            $orderData = $this->orderDataRepository->search($orderDataCriteria, $liveContext)->first();

            if ($orderData === null) {
                return;
            }

            $order = $this->getOrder($orderId, $liveContext);
            if ($order === null) {
                return;
            }

            $cancellation = $this->monduClient->setSalesChannelId($order->getSalesChannelId())->cancelInvoice(
                $orderData->getReferenceId(),
                $invoiceEntity->getExternalInvoiceUuid()
            );

            if ($cancellation !== null) {
                $this->invoiceDataRepository->update([[
                    'id' => $invoiceEntity->getId(),
                    InvoiceDataEntity::FIELD_INVOICE_STATE => 'cancelled',
                ]], $liveContext);

                $this->orderDataRepository->update([[
                    'id' => $orderData->getId(),
                    OrderDataEntity::FIELD_ORDER_STATE => 'authorized',
                ]], $liveContext);
            }
        } catch (\Throwable $e) {
            $this->logger->error('mondu.ERROR: StornoSubscriber failed: ' . $e->getMessage());
        }
    }

    private function getOrder(string $orderId, Context $context)
    {
        $criteria = CriteriaHelper::getCriteriaForOrder($orderId);
        return $this->orderRepository->search($criteria, $context)->first();
    }
}
