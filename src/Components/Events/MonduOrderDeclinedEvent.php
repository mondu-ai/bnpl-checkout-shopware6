<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Events;

use Shopware\Core\Framework\Event\ShopwareEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;

class MonduOrderDeclinedEvent implements ShopwareEvent
{
    private OrderEntity $order;
    private Context $context;
    private string $monduOrderId;
    private string $previousStatus;

    public function __construct(
        OrderEntity $order,
        string $monduOrderId,
        string $previousStatus,
        Context $context
    ) {
        $this->order = $order;
        $this->monduOrderId = $monduOrderId;
        $this->previousStatus = $previousStatus;
        $this->context = $context;
    }

    public function getOrder(): OrderEntity
    {
        return $this->order;
    }

    public function getMonduOrderId(): string
    {
        return $this->monduOrderId;
    }

    public function getPreviousStatus(): string
    {
        return $this->previousStatus;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    public function getName(): string
    {
        return 'mondu.order.declined';
    }
}
