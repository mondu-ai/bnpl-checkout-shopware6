<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Events\Subscriber;

use Mondu\MonduPayment\Components\Events\MonduOrderConfirmedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderCancelledEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderPendingEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderDeclinedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Kept as a hook point for merchants / future logic that wants to react to the
 * four Mondu order events. Intentionally empty — the previous implementation
 * only duplicated log lines already emitted by the dispatching code paths.
 */
class MonduOrderStatusSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            MonduOrderConfirmedEvent::class => 'onOrderConfirmed',
            MonduOrderCancelledEvent::class => 'onOrderCancelled',
            MonduOrderPendingEvent::class => 'onOrderPending',
            MonduOrderDeclinedEvent::class => 'onOrderDeclined',
        ];
    }

    public function onOrderConfirmed(MonduOrderConfirmedEvent $event): void {}
    public function onOrderCancelled(MonduOrderCancelledEvent $event): void {}
    public function onOrderPending(MonduOrderPendingEvent $event): void {}
    public function onOrderDeclined(MonduOrderDeclinedEvent $event): void {}
}
