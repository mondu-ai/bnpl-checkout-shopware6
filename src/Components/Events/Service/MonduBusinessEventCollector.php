<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Events\Service;

use Mondu\MonduPayment\Components\Events\MonduOrderConfirmedEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderCancelledEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderPendingEvent;
use Mondu\MonduPayment\Components\Events\MonduOrderDeclinedEvent;
use Shopware\Core\Framework\Event\BusinessEventCollector;
use Shopware\Core\Framework\Event\BusinessEventDefinition;

/**
 * Collector that registers Mondu events in Flow Builder
 */
class MonduBusinessEventCollector
{
    private BusinessEventCollector $businessEventCollector;

    public function __construct(BusinessEventCollector $businessEventCollector)
    {
        $this->businessEventCollector = $businessEventCollector;
    }

    /**
     * Get all Mondu business event definitions for Flow Builder
     *
     * @return BusinessEventDefinition[]
     */
    public function getMonduEventDefinitions(): array
    {
        $events = [
            MonduOrderConfirmedEvent::class,
            MonduOrderCancelledEvent::class,
            MonduOrderPendingEvent::class,
            MonduOrderDeclinedEvent::class,
        ];

        $definitions = [];
        foreach ($events as $eventClass) {
            $definitions[] = $this->createDefinition($eventClass);
        }

        return $definitions;
    }

    private function createDefinition(string $eventClass): BusinessEventDefinition
    {
        return new BusinessEventDefinition(
            $eventClass::EVENT_NAME,
            $eventClass,
            $eventClass::getAvailableData()
        );
    }
}
