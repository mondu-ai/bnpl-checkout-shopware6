<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Events\Subscriber;

use Mondu\MonduPayment\Components\Events\MonduOrderApprovedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderCancelledEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderConfirmedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderDeclinedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderPendingEvent;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Test subscriber to verify Mondu custom events are triggered
 * This can be disabled in production by commenting out the service registration
 */
class TestMonduEventsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ConfigService $configService
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MonduOrderConfirmedEvent::class => 'onOrderConfirmed',
            MonduOrderCancelledEvent::class => 'onOrderCancelled',
            MonduOrderPendingEvent::class => 'onOrderPending',
            MonduOrderApprovedEvent::class => 'onOrderApproved',
            MonduOrderDeclinedEvent::class => 'onOrderDeclined',
        ];
    }

    public function onOrderConfirmed(MonduOrderConfirmedEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: TEST SUBSCRIBER: Mondu Order Confirmed Event Triggered!', [
            'event_name' => 'mondu.order.confirmed',
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'mondu_order_id' => $event->getMonduOrderId(),
            'previous_status' => $event->getPreviousStatus(),
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    public function onOrderCancelled(MonduOrderCancelledEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: TEST SUBSCRIBER: Mondu Order Cancelled Event Triggered!', [
            'event_name' => 'mondu.order.cancelled',
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'mondu_order_id' => $event->getMonduOrderId(),
            'previous_status' => $event->getPreviousStatus(),
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    public function onOrderPending(MonduOrderPendingEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: TEST SUBSCRIBER: Mondu Order Pending Event Triggered!', [
            'event_name' => 'mondu.order.pending',
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'mondu_order_id' => $event->getMonduOrderId(),
            'previous_status' => $event->getPreviousStatus(),
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    public function onOrderApproved(MonduOrderApprovedEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: TEST SUBSCRIBER: Mondu Order Approved Event Triggered!', [
            'event_name' => 'mondu.order.approved',
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'mondu_order_id' => $event->getMonduOrderId(),
            'previous_status' => $event->getPreviousStatus(),
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }

    public function onOrderDeclined(MonduOrderDeclinedEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: TEST SUBSCRIBER: Mondu Order Declined Event Triggered!', [
            'event_name' => 'mondu.order.declined',
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'mondu_order_id' => $event->getMonduOrderId(),
            'previous_status' => $event->getPreviousStatus(),
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }
}

