<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Events\Subscriber;

use Mondu\MonduPayment\Components\Events\MonduOrderConfirmedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderCancelledEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderPendingEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderApprovedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderDeclinedEvent;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MonduOrderStatusSubscriber implements EventSubscriberInterface
{
    private LoggerInterface $logger;
    private ConfigService $configService;

    public function __construct(LoggerInterface $logger, ConfigService $configService)
    {
        $this->logger = $logger;
        $this->configService = $configService;
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
        
        $this->logger->info('mondu.INFO: Mondu Order Confirmed Event Triggered', [
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'mondu_order_id' => $event->getMonduOrderId(),
            'previous_status' => $event->getPreviousStatus(),
            'event_name' => $event->getName()
        ]);
    }

    public function onOrderCancelled(MonduOrderCancelledEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: Mondu Order Cancelled Event Triggered', [
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'mondu_order_id' => $event->getMonduOrderId(),
            'previous_status' => $event->getPreviousStatus(),
            'event_name' => $event->getName()
        ]);
    }

    public function onOrderPending(MonduOrderPendingEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: Mondu Order Pending Event Triggered', [
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'mondu_order_id' => $event->getMonduOrderId(),
            'previous_status' => $event->getPreviousStatus(),
            'event_name' => $event->getName()
        ]);
    }

    public function onOrderApproved(MonduOrderApprovedEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: Mondu Order Approved Event Triggered', [
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'mondu_order_id' => $event->getMonduOrderId(),
            'previous_status' => $event->getPreviousStatus(),
            'event_name' => $event->getName()
        ]);
    }

    public function onOrderDeclined(MonduOrderDeclinedEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: Mondu Order Declined Event Triggered', [
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'mondu_order_id' => $event->getMonduOrderId(),
            'previous_status' => $event->getPreviousStatus(),
            'event_name' => $event->getName()
        ]);
    }
}
