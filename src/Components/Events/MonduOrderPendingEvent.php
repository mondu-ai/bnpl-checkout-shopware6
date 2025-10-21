<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Events;

use Shopware\Core\Framework\Event\ShopwareEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\EntityType;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\OrderAware;
use Shopware\Core\Framework\Event\MailAware;
use Shopware\Core\Framework\Event\SalesChannelAware;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\CustomerAware;
use Shopware\Core\Framework\Event\CustomerGroupAware;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Framework\Event\EventData\MailRecipientStruct;

class MonduOrderPendingEvent implements ShopwareEvent, OrderAware, MailAware, SalesChannelAware, FlowEventAware, CustomerAware, CustomerGroupAware
{
    public const EVENT_NAME = 'mondu.order.pending';

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

    public function getOrderId(): string
    {
        return $this->order->getId();
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
        return self::EVENT_NAME;
    }

    public function getSalesChannelId(): string
    {
        return $this->order->getSalesChannelId();
    }

    public function getCustomerId(): string
    {
        $customerId = $this->order->getOrderCustomer()?->getCustomerId();

        if (!$customerId) {
            throw new \RuntimeException('Order customer not found');
        }

        return $customerId;
    }

    public function getCustomerGroupId(): string
    {
        $customerGroupId = $this->order->getOrderCustomer()?->getCustomer()?->getGroupId();

        if (!$customerGroupId) {
            throw new \RuntimeException('Customer group not found');
        }

        return $customerGroupId;
    }

    public function getMailStruct(): MailRecipientStruct
    {
        if (!$this->order->getOrderCustomer()) {
            throw new \RuntimeException('Order customer is required for mail sending');
        }

        return new MailRecipientStruct([
            $this->order->getOrderCustomer()->getEmail() => 
                $this->order->getOrderCustomer()->getFirstName() . ' ' . 
                $this->order->getOrderCustomer()->getLastName()
        ]);
    }

    public static function getAvailableData(): EventDataCollection
    {
        return (new EventDataCollection())
            ->add('order', new EntityType(OrderDefinition::class))
            ->add('monduOrderId', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('previousStatus', new ScalarValueType(ScalarValueType::TYPE_STRING));
    }
}
