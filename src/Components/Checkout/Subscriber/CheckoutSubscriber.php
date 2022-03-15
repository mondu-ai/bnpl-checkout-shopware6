<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Subscriber;

use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Storefront\Page\PageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CheckoutSubscriber implements EventSubscriberInterface
{
    private $configService;

    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => ['addWidgetData', 310],
            AccountEditOrderPageLoadedEvent::class => ['addWidgetData', 310],
        ];
    }

    public function addWidgetData(PageLoadedEvent $event): void
    {
        if ($event instanceof CheckoutConfirmPageLoadedEvent === false && $event instanceof AccountEditOrderPageLoadedEvent === false) {
            throw new \RuntimeException('method ' . __CLASS__ . '::' . __METHOD__ . ' does not supports a parameter of type' . get_class($event));
        }

        $paymentMethod = $event->getSalesChannelContext()->getPaymentMethod();
//        dd($event->getPage()->getPaymentMethods()->has($paymentMethod->getId()));
        if (MethodHelper::isMonduPayment($paymentMethod) && $event->getPage()->getPaymentMethods()->has($paymentMethod->getId())) {
            $extension = $event->getPage()->getExtension('mondu_checkout') ?? new ArrayStruct();
            $extension->set('config', ['src' => $this->configService->getWidgetUrl()]);
            $event->getPage()->addExtension('mondu_checkout', $extension);
        }
    }
}
