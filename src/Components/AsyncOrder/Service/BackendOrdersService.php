<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\AsyncOrder\Service;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Gate for creating Mondu orders in the admin (POST /orders/create_async).
 *
 * Two things have to line up: the merchant switches the feature on in the plugin
 * config, and Mondu has the async order source enabled for the account. The second
 * one has no dedicated endpoint, so it is probed once an hour and cached. The cache
 * key carries the API token and the mode, so a new token is probed right away.
 */
class BackendOrdersService
{
    private const CACHE_KEY_PREFIX = 'mondu_async_order_support_';
    private const CACHE_LIFETIME = 3600;

    public function __construct(
        private readonly ConfigService $configService,
        private readonly MonduClient $monduClient,
        private readonly CacheItemPoolInterface $cache
    ) {}

    /**
     * True when the merchant switched admin orders on in the plugin config.
     */
    public function isEnabled(?string $salesChannelId): bool
    {
        $config = $this->configService->setSalesChannelId($salesChannelId);

        return $config->getApiTokenValid() && $config->isBackendOrdersEnabled();
    }

    /**
     * True when Mondu has the async order source switched on for this account.
     */
    public function isAvailable(?string $salesChannelId): bool
    {
        $item = $this->cache->getItem($this->getCacheKey($salesChannelId));
        if ($item->isHit()) {
            return (bool) $item->get();
        }

        $status = $this->monduClient->setSalesChannelId($salesChannelId)->probeAsyncOrders();

        // A failed probe (5xx, timeout, proxy noise) says nothing about the account: keep
        // the feature on and do not cache the non-answer.
        if ($status === null || $status >= 500) {
            return true;
        }

        $available = !in_array($status, [401, 403, 404], true);

        $item->set($available);
        $item->expiresAfter(self::CACHE_LIFETIME);
        $this->cache->save($item);

        return $available;
    }

    /**
     * True when Mondu payment methods should be offered for admin orders at all.
     */
    public function isActive(?string $salesChannelId): bool
    {
        return $this->isEnabled($salesChannelId) && $this->isAvailable($salesChannelId);
    }

    private function getCacheKey(?string $salesChannelId): string
    {
        $config = $this->configService->setSalesChannelId($salesChannelId);

        return self::CACHE_KEY_PREFIX . md5(($salesChannelId ?? 'default') . '|' . $config->getApiToken() . '|' . (int) $config->isSandbox());
    }
}
