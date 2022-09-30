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
            CheckoutConfirmPageLoadedEvent::class => ['addWidgetData', 310],
            AccountEditOrderPageLoadedEvent::class => ['addWidgetData', 310],
            'sales_channel.payment_method.process.criteria' => 'filterPaymentMethods'
        ];
    }

    public function addWidgetData(PageLoadedEvent $event): void
    {
        if ($event instanceof CheckoutConfirmPageLoadedEvent === false && $event instanceof AccountEditOrderPageLoadedEvent === false) {
            throw new \RuntimeException('method ' . __CLASS__ . '::' . __METHOD__ . ' does not supports a parameter of type' . get_class($event));
        }

        $paymentMethod = $event->getSalesChannelContext()->getPaymentMethod();
        if (MethodHelper::isMonduPayment($paymentMethod) && $event->getPage()->getPaymentMethods()->has($paymentMethod->getId())) {
            $extension = $event->getPage()->getExtension('mondu_checkout') ?? new ArrayStruct();
            $extension->set('config',
                [
                    'src' => $this->configService->setSalesChannelId(
                                $event->getSalesChannelContext()->getSalesChannelId()
                             )->getWidgetUrl(),
                    'payment_method' => MethodHelper::shortNameToMonduName($paymentMethod->getShortName())
                ]
            );
            $event->getPage()->addExtension('mondu_checkout', $extension);
        }
    }

    public function filterPaymentMethods(SalesChannelProcessCriteriaEvent $event) {
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

        $event->getCriteria()->addFilter(new NotFilter(
            NotFilter::CONNECTION_OR,
            array_map(function ($val) {
                return new EqualsFilter('handlerIdentifier', MethodHelper::monduNameToHandler($val));
            }, $disallowedPaymentMethods)
        ));
    }
}
