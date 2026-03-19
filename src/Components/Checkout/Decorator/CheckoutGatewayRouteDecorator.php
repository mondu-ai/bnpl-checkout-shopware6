<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Checkout\Decorator;

use Mondu\MonduPayment\Components\Checkout\Service\PaymentMethodFilterService;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Gateway\SalesChannel\AbstractCheckoutGatewayRoute;
use Shopware\Core\Checkout\Gateway\SalesChannel\CheckoutGatewayRouteResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

class CheckoutGatewayRouteDecorator extends AbstractCheckoutGatewayRoute
{
    public function __construct(
        private readonly AbstractCheckoutGatewayRoute $inner,
        private readonly PaymentMethodFilterService $paymentMethodFilterService,
        private readonly ConfigService $configService,
        private readonly LoggerInterface $logger
    ) {}

    public function getDecorated(): AbstractCheckoutGatewayRoute
    {
        return $this->inner;
    }

    public function load(Request $request, Cart $cart, SalesChannelContext $context): CheckoutGatewayRouteResponse
    {
        $response = $this->inner->load($request, $cart, $context);
        $disallowedHandlers = $this->paymentMethodFilterService->getDisallowedMonduHandlers($context);

        if ($disallowedHandlers === []) {
            return $response;
        }

        $paymentMethods = $response->getPaymentMethods()->filter(
            static fn ($method) => !in_array($method->getHandlerIdentifier(), $disallowedHandlers, true)
        );

        $this->configService->setSalesChannelId($context->getSalesChannelId());

        return new CheckoutGatewayRouteResponse(
            $paymentMethods,
            $response->getShippingMethods(),
            $response->getErrors()
        );
    }
}
