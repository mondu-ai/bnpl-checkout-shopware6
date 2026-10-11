<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\AsyncOrder;

use Mondu\MonduPayment\Components\AsyncOrder\Service\PaymentTermsService;
use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class PaymentTermsServiceTest extends TestCase
{
    public function testTermsAreReadForTheAsyncSourceAndNarrowedToTheCountry(): void
    {
        $client = $this->client([
            ['net_term' => 60, 'country_code' => 'DE'],
            ['net_term' => 30, 'country_code' => 'DE'],
            ['net_term' => 30, 'country_code' => 'DE'],
            ['net_term' => 14, 'country_code' => 'NL'],
        ]);
        $client->expects(static::once())->method('getPaymentTerms')->with(['source' => 'async']);

        $service = new PaymentTermsService($this->config(), $client, new ArrayAdapter());

        static::assertSame([30, 60], $service->getNetTerms(null, 'DE'));
        // Read once, then served from the cache.
        static::assertSame([14], $service->getNetTerms(null, 'NL'));
        // A country the merchant holds no term for gets none, not another country's.
        static::assertSame([], $service->getNetTerms(null, 'BE'));
    }

    public function testThirtyDaysOrTheClosestTermIsPreselected(): void
    {
        $service = new PaymentTermsService($this->config(), $this->client([]), new ArrayAdapter());

        static::assertSame(30, $service->pickDefault([14, 30, 60]));
        static::assertSame(14, $service->pickDefault([14, 46]));
        static::assertSame(45, $service->pickDefault([7, 45]));
        static::assertNull($service->pickDefault([]));
    }

    public function testAFailedReadIsNotCached(): void
    {
        $client = $this->createMock(MonduClient::class);
        $client->method('setSalesChannelId')->willReturnSelf();
        $client->expects(static::exactly(2))->method('getPaymentTerms')->willReturn(null);

        $service = new PaymentTermsService($this->config(), $client, new ArrayAdapter());

        static::assertSame([], $service->getNetTerms(null, 'DE'));
        static::assertSame([], $service->getNetTerms(null, 'DE'));
    }

    private function client(array $terms): MonduClient
    {
        $client = $this->createMock(MonduClient::class);
        $client->method('setSalesChannelId')->willReturnSelf();
        $client->method('getPaymentTerms')->willReturn($terms);

        return $client;
    }

    private function config(): ConfigService
    {
        $config = $this->createMock(ConfigService::class);
        $config->method('setSalesChannelId')->willReturnSelf();
        $config->method('getApiToken')->willReturn('token');
        $config->method('isSandbox')->willReturn(true);

        return $config;
    }
}
