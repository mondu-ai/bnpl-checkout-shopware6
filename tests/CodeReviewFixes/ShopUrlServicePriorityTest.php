<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\CodeReviewFixes;

use Mondu\MonduPayment\Components\Webhooks\Service\ShopUrlService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Tests that ShopUrlService prioritizes the DB sales channel domain
 * over $_SERVER['HTTP_ORIGIN'].
 */
class ShopUrlServicePriorityTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_HOST']);
    }

    public function testDbDomainTakesPriorityOverHttpOrigin(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://attacker-controlled.example.com';

        $domain = new SalesChannelDomainEntity();
        $domain->setId('domain-1');
        $domain->setUrl('https://trusted-shop.example.com');

        $salesChannel = $this->createMock(SalesChannelEntity::class);
        $salesChannel->method('getDomains')->willReturn(
            new SalesChannelDomainCollection([$domain])
        );

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn($salesChannel);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($searchResult);

        $service = new ShopUrlService($repository, new NullLogger());

        $url = $service->getShopUrl('sc-123');

        static::assertSame('https://trusted-shop.example.com', $url);
        static::assertNotSame($_SERVER['HTTP_ORIGIN'], $url);
    }

    public function testFallsBackToHttpOriginWhenNoSalesChannel(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://my-shop.example.com';

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('first')->willReturn(null);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturn($searchResult);

        $service = new ShopUrlService($repository, new NullLogger());

        $url = $service->getShopUrl('sc-123');

        static::assertSame('https://my-shop.example.com', $url);
    }

    public function testFallsBackToHttpOriginWhenNoSalesChannelId(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://my-shop.example.com';

        $repository = $this->createMock(EntityRepository::class);
        $service = new ShopUrlService($repository, new NullLogger());

        $url = $service->getShopUrl(null);

        static::assertSame('https://my-shop.example.com', $url);
    }
}
