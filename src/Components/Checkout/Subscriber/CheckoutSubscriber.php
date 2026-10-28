<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Subscriber;

use Mondu\MonduPayment\Components\Checkout\Service\NetTermOfferService;
use Mondu\MonduPayment\Components\Checkout\Service\PaymentMethodFilterService;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutSubscriber implements EventSubscriberInterface
{
    /**
     * Page extension the payment method template reads the net term choice from.
     */
    public const NET_TERMS_EXTENSION = 'monduNetTerms';

    public function __construct(
        private readonly PaymentMethodFilterService $paymentMethodFilterService,
        private readonly NetTermOfferService $netTermOfferService
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
        $this->addNetTerms($event);
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

    /**
     * Net terms each Mondu payment method on the page offers, keyed by payment method id.
     *
     * The edit order page pays an order that already exists, so its billing address
     * counts there; the confirm page uses the address the order will be placed with.
     */
    private function addNetTerms(PageLoadedEvent $event): void
    {
        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannelId();
        $page = $event->getPage();

        $countryCode = $event instanceof AccountEditOrderPageLoadedEvent
            ? $page->getOrder()->getBillingAddress()?->getCountry()?->getIso()
            : $salesChannelContext->getCustomer()?->getActiveBillingAddress()?->getCountry()?->getIso();

        $offers = [];
        foreach ($page->getPaymentMethods() as $paymentMethod) {
            if (!MethodHelper::isMonduPayment($paymentMethod)) {
                continue;
            }

            $netTerms = $this->netTermOfferService->getOfferedNetTerms(
                $salesChannelId,
                MethodHelper::shortNameToMonduName($paymentMethod->getShortName()),
                $countryCode
            );

            if ($netTerms !== []) {
                $offers[$paymentMethod->getId()] = [
                    'netTerms' => $netTerms,
                    'default' => $this->netTermOfferService->pickDefault($netTerms),
                ];
            }
        }

        $page->addExtension(self::NET_TERMS_EXTENSION, new ArrayStruct($offers));
    }

}
