<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\AsyncOrder\Service;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\Webhooks\Service\WebhookService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;

/**
 * Applies the state Mondu holds for an order created in the admin, once the order is written.
 *
 * Mondu assesses an async order right away and may send its webhook while Shopware is
 * still writing the order. That webhook finds no order and only lands on Mondu's retry.
 * So once the order exists, its state is read from Mondu and applied through the same
 * handlers as the webhooks. A webhook that arrives afterwards finds the transition already
 * made, which the handlers treat as a no-op.
 */
class AsyncOrderStateSync
{
    public function __construct(
        private readonly MonduClient $monduClient,
        private readonly WebhookService $webhookService,
        private readonly LoggerInterface $logger
    ) {}

    public function sync(OrderDataEntity $orderData, OrderEntity $order, Context $context): void
    {
        $monduOrder = $this->monduClient
            ->setSalesChannelId($order->getSalesChannelId())
            ->getMonduOrder($orderData->getReferenceId());

        $state = $monduOrder['state'] ?? null;
        if ($state === null) {
            return;
        }

        $params = [
            'topic' => 'order/' . $state,
            'order_uuid' => $orderData->getReferenceId(),
            'external_reference_id' => $order->getOrderNumber(),
            'order_state' => $state,
            'bank_account' => $monduOrder['bank_account'] ?? null,
        ];

        $webhookService = $this->webhookService->setSalesChannelId($order->getSalesChannelId());

        $result = match ($state) {
            'pending' => $webhookService->handlePending($params, $context),
            'authorized' => $webhookService->handleAuthorized($params, $context),
            'confirmed' => $webhookService->handleConfirmed($params, $context),
            'declined', 'canceled' => $webhookService->handleDeclinedOrCanceled($params, $context),
            default => null,
        };

        $this->logger->info('mondu.INFO: Applied the Mondu state of an order created in the admin', [
            'order_number' => $order->getOrderNumber(),
            'mondu_uuid' => $orderData->getReferenceId(),
            'mondu_state' => $state,
            'result' => $result,
        ]);
    }
}
