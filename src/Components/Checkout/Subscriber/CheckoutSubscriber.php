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
            CheckoutConfirmPageLoadedEvent::class => 'onPageLoaded',
            AccountEditOrderPageLoadedEvent::class => 'onPageLoaded'
        ];
    }

    /**
     * Guards the event type, then hands off to the payment-method filter.
     *
     * @throws \RuntimeException when dispatched for an unsupported event
     */
    public function onPageLoaded(PageLoadedEvent $event): void
    {
        if ($event instanceof CheckoutConfirmPageLoadedEvent === false && $event instanceof AccountEditOrderPageLoadedEvent === false) {
            throw new \RuntimeException(__METHOD__ . ' does not support a parameter of type ' . get_class($event));
        }

        $this->filterPaymentMethods($event);
    }

    /**
     * @deprecated Renamed to onPageLoaded(); kept so a container compiled before
     *             the rename still resolves. Remove in the next major.
     */
    public function addWidgetData(PageLoadedEvent $event): void
    {
        $this->onPageLoaded($event);
    }

    public function filterPaymentMethods(PageLoadedEvent $event): void
    {
        $disallowedHandlers = $this->paymentMethodFilterService->getDisallowedMonduHandlers(
            $event->getSalesChannelContext()
        );

        $paymentMethods = $event->getPage()->getPaymentMethods()->filter(
            static function (PaymentMethodEntity $paymentMethod) use ($disallowedHandlers) {
                return !in_array($paymentMethod->getHandlerIdentifier(), $disallowedHandlers);
            }
        );

        $event->getPage()->setPaymentMethods($paymentMethods);
    }
}
