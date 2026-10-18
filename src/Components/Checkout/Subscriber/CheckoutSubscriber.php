<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Subscriber;

use Mondu\MonduPayment\Components\Checkout\Service\NetTermOfferService;
use Mondu\MonduPayment\Components\Checkout\Service\PaymentMethodFilterService;
use Mondu\MonduPayment\Components\Order\Model\OrderDataEntity;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Util\NetTermName;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
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
        private readonly NetTermOfferService $netTermOfferService,
        private readonly EntityRepository $orderDataRepository
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onPageLoaded',
            AccountEditOrderPageLoadedEvent::class => 'onPageLoaded',
            CheckoutFinishPageLoadedEvent::class => 'onFinishPageLoaded',
            AccountOrderPageLoadedEvent::class => 'onOrderListLoaded',
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
        $contextMethod = $salesChannelContext->getPaymentMethod();
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
                NetTermName::apply($paymentMethod, $netTerms);
                if ($contextMethod->getId() === $paymentMethod->getId()) {
                    NetTermName::apply($contextMethod, $netTerms);
                }

                $offers[$paymentMethod->getId()] = [
                    'netTerms' => $netTerms,
                    'default' => $this->netTermOfferService->pickDefault($netTerms),
                ];
            }
        }

        $page->addExtension(self::NET_TERMS_EXTENSION, new ArrayStruct($offers));
    }

    /**
     * The order just placed, named with the term it went to Mondu with.
     */
    public function onFinishPageLoaded(CheckoutFinishPageLoadedEvent $event): void
    {
        $this->nameOrdersWithTheirTerm(new OrderCollection([$event->getPage()->getOrder()]), $event->getContext());
    }

    /**
     * The account's order history, each order named with its own term.
     */
    public function onOrderListLoaded(AccountOrderPageLoadedEvent $event): void
    {
        $orders = $event->getPage()->getOrders()->getEntities();
        if ($orders instanceof OrderCollection && $orders->count() > 0) {
            $this->nameOrdersWithTheirTerm($orders, $event->getContext());
        }
    }

    /**
     * The term of a placed order is the one Mondu authorized, or the one the buyer picked
     * until Mondu decided (see MonduHandler::saveEarlyOrderData()).
     */
    private function nameOrdersWithTheirTerm(OrderCollection $orders, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter(OrderDataEntity::FIELD_ORDER_ID, array_values($orders->getIds())));

        $terms = [];
        /** @var OrderDataEntity $data */
        foreach ($this->orderDataRepository->search($criteria, $context) as $data) {
            if ((int) $data->getDuration() > 0) {
                $terms[$data->getOrderId()] = [(int) $data->getDuration()];
            }
        }

        foreach ($orders as $order) {
            if (!isset($terms[$order->getId()])) {
                continue;
            }

            $transactions = $order->getTransactions()?->getElements() ?? [];
            if (method_exists($order, 'getPrimaryOrderTransaction') && $order->getPrimaryOrderTransaction() !== null) {
                $transactions[] = $order->getPrimaryOrderTransaction();
            }

            foreach ($transactions as $transaction) {
                $paymentMethod = $transaction->getPaymentMethod();
                if ($paymentMethod !== null && MethodHelper::isMonduPayment($paymentMethod)) {
                    // Orders may share one hydrated payment method, so each gets its own copy.
                    $paymentMethod = clone $paymentMethod;
                    NetTermName::apply($paymentMethod, $terms[$order->getId()]);
                    $transaction->setPaymentMethod($paymentMethod);
                }
            }
        }
    }
}
