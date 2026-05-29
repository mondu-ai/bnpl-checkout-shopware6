<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Subscriber;

use Mondu\MonduPayment\Components\Checkout\Service\PaymentMethodFilterService;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PaymentMethodFilterService $paymentMethodFilterService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'addWidgetData',
            AccountEditOrderPageLoadedEvent::class => 'addWidgetData'
        ];
    }

    public function addWidgetData(PageLoadedEvent $event): void
    {
        $this->filterPaymentMethods($event);
    }

    public function filterPaymentMethods(PageLoadedEvent $event): void
    {
        $disallowedHandlers = $this->paymentMethodFilterService->getDisallowedMonduHandlers(
            $event->getSalesChannelContext()
        );

        $paymentMethods = $event->getPage()->getPaymentMethods()->filter(
            static function (PaymentMethodEntity $paymentMethod) use ($disallowedHandlers) {
                return !in_array($paymentMethod->getHandlerIdentifier(), $disallowedHandlers, true);
            }
        );

        $event->getPage()->setPaymentMethods($paymentMethods);
    }
}
