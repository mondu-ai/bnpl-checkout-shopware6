<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Events\Service;

use Mondu\MonduPayment\Components\Events\MonduOrderConfirmedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderCancelledEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderPendingEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderDeclinedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class MonduEventDispatcher
{
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function dispatchOrderConfirmed(
        OrderEntity $order,
        string $monduOrderId,
        string $previousStatus,
        Context $context
    ): void {
        $event = new MonduOrderConfirmedEvent($order, $monduOrderId, $previousStatus, $context);
        $this->eventDispatcher->dispatch($event);
    }

    public function dispatchOrderCancelled(
        OrderEntity $order,
        string $monduOrderId,
        string $previousStatus,
        Context $context
    ): void {
        $event = new MonduOrderCancelledEvent($order, $monduOrderId, $previousStatus, $context);
        $this->eventDispatcher->dispatch($event);
    }

    public function dispatchOrderPending(
        OrderEntity $order,
        string $monduOrderId,
        string $previousStatus,
        Context $context
    ): void {
        $event = new MonduOrderPendingEvent($order, $monduOrderId, $previousStatus, $context);
        $this->eventDispatcher->dispatch($event);
    }


    public function dispatchOrderDeclined(
        OrderEntity $order,
        string $monduOrderId,
        string $previousStatus,
        Context $context
    ): void {
        $event = new MonduOrderDeclinedEvent($order, $monduOrderId, $previousStatus, $context);
        $this->eventDispatcher->dispatch($event);
    }
}
