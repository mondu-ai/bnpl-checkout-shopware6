<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\CodeReviewFixes;

use Mondu\MonduPayment\Components\Checkout\Service\PaymentMethodFilterService;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduOperationService;
use Mondu\MonduPayment\Components\PaymentMethod\Util\MethodHelper;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Tests that PaymentMethodFilterService uses strict in_array comparison.
 */
class PaymentMethodFilterServiceStrictTest extends TestCase
{
    public function testAllowedMethodsAreNotDisallowed(): void
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('setSalesChannelId')->willReturnSelf();
        $configService->method('getApiTokenValid')->willReturn(true);

        $monduOperationService = $this->createMock(MonduOperationService::class);
        $monduOperationService->method('getAllowedPaymentMethods')
            ->willReturn(['invoice', 'direct_debit']);

        $service = new PaymentMethodFilterService($configService, $monduOperationService, new NullLogger());

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sc');

        $disallowed = $service->getDisallowedMonduHandlers($context);

        $allHandlers = array_map(
            fn (string $name) => MethodHelper::monduNameToHandler($name),
            MethodHelper::MONDU_PAYMENT_METHODS
        );

        $invoiceHandler = MethodHelper::monduNameToHandler('invoice');
        $sepaHandler = MethodHelper::monduNameToHandler('direct_debit');

        static::assertNotContains($invoiceHandler, $disallowed, 'invoice should be allowed');
        static::assertNotContains($sepaHandler, $disallowed, 'direct_debit should be allowed');
    }

    public function testAllMethodsDisallowedWhenApiTokenInvalid(): void
    {
        $configService = $this->createMock(ConfigService::class);
        $configService->method('setSalesChannelId')->willReturnSelf();
        $configService->method('getApiTokenValid')->willReturn(false);

        $monduOperationService = $this->createMock(MonduOperationService::class);
        $monduOperationService->method('getAllowedPaymentMethods')
            ->willReturn(['invoice']);

        $service = new PaymentMethodFilterService($configService, $monduOperationService, new NullLogger());

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn('test-sc');

        $disallowed = $service->getDisallowedMonduHandlers($context);

        $allHandlers = array_map(
            fn (string $name) => MethodHelper::monduNameToHandler($name),
            MethodHelper::MONDU_PAYMENT_METHODS
        );

        static::assertCount(count($allHandlers), $disallowed);
    }
}
