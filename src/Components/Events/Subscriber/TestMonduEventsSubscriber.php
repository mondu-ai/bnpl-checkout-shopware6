<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Events\Subscriber;

use Mondu\MonduPayment\Components\Events\MonduOrderConfirmedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderCancelledEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderPendingEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderDeclinedEvent;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class TestMonduEventsSubscriber implements EventSubscriberInterface
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
            MonduOrderConfirmedEvent::EVENT_NAME => 'onOrderConfirmed',
            MonduOrderCancelledEvent::EVENT_NAME => 'onOrderCancelled',
            MonduOrderPendingEvent::EVENT_NAME => 'onOrderPending',
            MonduOrderDeclinedEvent::EVENT_NAME => 'onOrderDeclined',
        ];
    }

    public function onOrderConfirmed(MonduOrderConfirmedEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: TEST SUBSCRIBER: Mondu Order Confirmed Event Triggered!', [
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'event_name' => $event->getName()
        ]);
    }

    public function onOrderCancelled(MonduOrderCancelledEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $order = $event->getOrder();
        $customer = $order->getOrderCustomer() ? $order->getOrderCustomer()->getCustomer() : null;
        
        $this->logger->info('mondu.INFO: TEST SUBSCRIBER: Mondu Order Cancelled Event Triggered!', [
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'event_name' => $event->getName(),
            'event_class' => get_class($event),
            'has_customer' => $customer !== null,
            'customer_id' => $customer ? $customer->getId() : 'null',
            'sales_channel_id' => $event->getSalesChannelId()
        ]);
    }

    public function onOrderPending(MonduOrderPendingEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: TEST SUBSCRIBER: Mondu Order Pending Event Triggered!', [
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'event_name' => $event->getName()
        ]);
    }

    public function onOrderDeclined(MonduOrderDeclinedEvent $event): void
    {
        if (!$this->configService->isExtendedLogsEnabled()) {
            return;
        }
        
        $this->logger->info('mondu.INFO: TEST SUBSCRIBER: Mondu Order Declined Event Triggered!', [
            'order_id' => $event->getOrder()->getId(),
            'order_number' => $event->getOrder()->getOrderNumber(),
            'event_name' => $event->getName()
        ]);
    }
}
