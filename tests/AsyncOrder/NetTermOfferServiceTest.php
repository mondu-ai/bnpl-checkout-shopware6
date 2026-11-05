<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\AsyncOrder;

use Mondu\MonduPayment\Components\AsyncOrder\Service\PaymentTermsService;
use Mondu\MonduPayment\Components\Checkout\Service\NetTermOfferService;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class NetTermOfferServiceTest extends TestCase
{
    /**
     * What the API holds for the storefront, per payment method.
     */
    private const WIDGET_TERMS = [
        'invoice' => [
            ['net_term' => 14, 'country_code' => 'DE'],
            ['net_term' => 30, 'country_code' => 'DE'],
            ['net_term' => 60, 'country_code' => 'DE'],
            ['net_term' => 30, 'country_code' => 'NL'],
        ],
        'pay_now' => [
            ['net_term' => 3, 'country_code' => 'DE'],
        ],
    ];

    public function testTheMerchantsTermsAreNarrowedToWhatTheBuyersCountryHolds(): void
    {
        $service = $this->service(['invoice' => [30, 60, 90], 'pay_now' => [3]]);

        // 90 is enabled but the account does not hold it.
        static::assertSame([30, 60], $service->getOfferedNetTerms(null, 'invoice', 'DE'));
        static::assertSame([30], $service->getOfferedNetTerms(null, 'invoice', 'NL'));
        static::assertSame([], $service->getOfferedNetTerms(null, 'invoice', 'FR'));
        static::assertSame([3], $service->getOfferedNetTerms(null, 'pay_now', 'DE'));
    }

    public function testNothingIsOfferedWithoutTheMerchantsChoiceOrForInstallments(): void
    {
        $service = $this->service(['invoice' => [], 'installment' => [30]]);

        static::assertSame([], $service->getOfferedNetTerms(null, 'invoice', 'DE'));
        static::assertSame([], $service->getOfferedNetTerms(null, 'installment', 'DE'));
        static::assertNull($service->resolve(null, 'invoice', 'DE', '30'));
    }

    public function testTheBuyersChoiceIsSentOnlyWhenItIsOnOffer(): void
    {
        $service = $this->service(['invoice' => [14, 30, 60]]);

        static::assertSame(60, $service->resolve(null, 'invoice', 'DE', '60'));
        // Missing or tampered with: the preselected term instead.
        static::assertSame(30, $service->resolve(null, 'invoice', 'DE', null));
        static::assertSame(30, $service->resolve(null, 'invoice', 'DE', '90'));
        static::assertSame(30, $service->resolve(null, 'invoice', 'DE', 'abc'));
    }

    /**
     * @param array<string, int[]> $enabled Terms the merchant enabled per Mondu payment method
     */
    private function service(array $enabled): NetTermOfferService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('setSalesChannelId')->willReturnSelf();
        $config->method('getApiToken')->willReturn('token');
        $config->method('isSandbox')->willReturn(true);
        $config->method('getAvailableNetTerms')->willReturnCallback(
            static fn (string $method) => $enabled[$method] ?? []
        );

        $client = $this->createMock(MonduClient::class);
        $client->method('setSalesChannelId')->willReturnSelf();
        $client->method('getPaymentTerms')->willReturnCallback(
            static function (array $filters): array {
                TestCase::assertSame('widget', $filters['source']);

                return self::WIDGET_TERMS[$filters['payment_method']] ?? [];
            }
        );

        return new NetTermOfferService($config, new PaymentTermsService($config, $client, new ArrayAdapter()));
    }
}
