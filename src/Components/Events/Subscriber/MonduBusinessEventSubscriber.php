<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Events\Subscriber;

use Mondu\MonduPayment\Components\Events\MonduOrderConfirmedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderCancelledEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderPendingEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderApprovedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderDeclinedEvent;
use Shopware\Core\Framework\Event\BusinessEventCollectorEvent;
use Shopware\Core\Framework\Event\BusinessEventDefinition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to BusinessEventCollectorEvent to register Mondu events in Flow Builder
 */
class MonduBusinessEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            BusinessEventCollectorEvent::NAME => 'onCollectBusinessEvents',
        ];
    }

    public function onCollectBusinessEvents(BusinessEventCollectorEvent $event): void
    {
        $collection = $event->getCollection();

        // Define aware interfaces for all Mondu events
        $aware = ['order', 'salesChannel', 'mail'];

        // Register all Mondu events
        $collection->set(
            MonduOrderConfirmedEvent::EVENT_NAME,
            new BusinessEventDefinition(
                MonduOrderConfirmedEvent::EVENT_NAME,
                MonduOrderConfirmedEvent::class,
                MonduOrderConfirmedEvent::getAvailableData()->toArray(),
                $aware
            )
        );

        $collection->set(
            MonduOrderCancelledEvent::EVENT_NAME,
            new BusinessEventDefinition(
                MonduOrderCancelledEvent::EVENT_NAME,
                MonduOrderCancelledEvent::class,
                MonduOrderCancelledEvent::getAvailableData()->toArray(),
                $aware
            )
        );

        $collection->set(
            MonduOrderPendingEvent::EVENT_NAME,
            new BusinessEventDefinition(
                MonduOrderPendingEvent::EVENT_NAME,
                MonduOrderPendingEvent::class,
                MonduOrderPendingEvent::getAvailableData()->toArray(),
                $aware
            )
        );

        $collection->set(
            MonduOrderApprovedEvent::EVENT_NAME,
            new BusinessEventDefinition(
                MonduOrderApprovedEvent::EVENT_NAME,
                MonduOrderApprovedEvent::class,
                MonduOrderApprovedEvent::getAvailableData()->toArray(),
                $aware
            )
        );

        $collection->set(
            MonduOrderDeclinedEvent::EVENT_NAME,
            new BusinessEventDefinition(
                MonduOrderDeclinedEvent::EVENT_NAME,
                MonduOrderDeclinedEvent::class,
                MonduOrderDeclinedEvent::getAvailableData()->toArray(),
                $aware
            )
        );
    }
}

