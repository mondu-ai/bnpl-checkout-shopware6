<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Subscriber;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduOperationService;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\Event\SalesChannelProcessCriteriaEvent;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutSubscriber implements EventSubscriberInterface
{
    private $configService;
    private MonduOperationService $monduOperationService;

    public function __construct(ConfigService $configService, MonduOperationService $monduOperationService)
    {
        $this->configService = $configService;
        $this->monduOperationService = $monduOperationService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'addWidgetData',
            AccountEditOrderPageLoadedEvent::class => 'addWidgetData'
        ];
    }

    public function addWidgetData(PageLoadedEvent $event): void
    {
        if ($event instanceof CheckoutConfirmPageLoadedEvent === false && $event instanceof AccountEditOrderPageLoadedEvent === false) {
            throw new \RuntimeException('method ' . __CLASS__ . '::' . __METHOD__ . ' does not supports a parameter of type' . get_class($event));
        }

        $this->filterPaymentMethods($event);
    }

    public function filterPaymentMethods(PageLoadedEvent $event) {
        $allowedPaymentMethods = $this->monduOperationService->getAllowedPaymentMethods($event->getSalesChannelContext()->getSalesChannelId());
        $disallowedPaymentMethods = [];
        $allPaymentMethods = MethodHelper::MONDU_PAYMENT_METHODS;

        foreach($allPaymentMethods as $value) {
            if(!in_array($value, $allowedPaymentMethods)) {
                $disallowedPaymentMethods[] = $value;
            }
        }

        if(!$this->configService->setSalesChannelId($event->getSalesChannelContext()->getSalesChannelId())->getApiTokenValid()) {
            $disallowedPaymentMethods = $allPaymentMethods;
        }

        $disallowedPaymentMethodsMapped = array_map(function ($val) {
            return MethodHelper::monduNameToHandler($val);
        }, $disallowedPaymentMethods);

        $paymentMethods = $event->getPage()->getPaymentMethods();

        $paymentMethods = $paymentMethods->filter(
            static function (PaymentMethodEntity $paymentMethod) use ($disallowedPaymentMethodsMapped) {
                return !in_array($paymentMethod->getHandlerIdentifier(), $disallowedPaymentMethodsMapped);
            }
        );

        $event->getPage()->setPaymentMethods($paymentMethods);
    }
}
