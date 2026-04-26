<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Decorator;

use Mondu\MonduPayment\Components\Checkout\Service\PaymentMethodFilterService;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\SalesChannel\PaymentMethodRouteResponse;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filters Mondu payment methods at the source.
 * Ensures filtering even when CachedPaymentMethodRoute serves from cache.
 */
class CachedPaymentMethodRouteDecorator extends AbstractPaymentMethodRoute
{
    public function __construct(
        private readonly AbstractPaymentMethodRoute $inner,
        private readonly PaymentMethodFilterService $paymentMethodFilterService,
        private readonly ConfigService $configService,
        private readonly LoggerInterface $logger
    ) {}

    public function getDecorated(): AbstractPaymentMethodRoute
    {
        return $this->inner;
    }

    public function load(Request $request, SalesChannelContext $context, Criteria $criteria): PaymentMethodRouteResponse
    {
        $response = $this->inner->load($request, $context, $criteria);
        $disallowedHandlers = $this->paymentMethodFilterService->getDisallowedMonduHandlers($context);

        if ($disallowedHandlers === []) {
            return $response;
        }

        $filtered = $response->getObject()->filter(
            static fn ($method) => !in_array($method->getHandlerIdentifier(), $disallowedHandlers, true)
        );
        $this->configService->setSalesChannelId($context->getSalesChannelId());

        return new PaymentMethodRouteResponse($filtered);
    }
}
