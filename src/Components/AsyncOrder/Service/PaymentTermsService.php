<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\AsyncOrder\Service;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Net terms the merchant may offer on an order created in the admin.
 *
 * Read from GET /api/v1/payment_terms?source=async. Without the source the API merges
 * the terms of every order source the merchant has, and the admin would offer a term
 * the merchant only holds for the storefront, which Mondu then refuses with
 * "net term not available". The list is narrowed to the buyer's country here, the
 * same way the Magento module does it.
 */
class PaymentTermsService
{
    public const SOURCE_ASYNC = 'async';

    /**
     * Preselected whenever the merchant may use it.
     */
    public const PREFERRED_NET_TERM = 30;

    private const CACHE_KEY_PREFIX = 'mondu_payment_terms_';
    private const CACHE_LIFETIME = 3600;

    public function __construct(
        private readonly ConfigService $configService,
        private readonly MonduClient $monduClient,
        private readonly CacheItemPoolInterface $cache
    ) {}

    /**
     * Sorted, unique net terms for the buyer's country.
     *
     * A country the merchant holds no terms for yields an empty list rather than the
     * terms of other countries: the API validates the term against the buyer's country,
     * so offering another country's term only produces a 422 later on.
     *
     * @return int[]
     */
    public function getNetTerms(?string $salesChannelId, ?string $countryCode): array
    {
        $netTerms = [];

        foreach ($this->getPaymentTerms($salesChannelId) as $term) {
            if (!isset($term['net_term'])) {
                continue;
            }

            $matchesCountry = $countryCode === null
                || !isset($term['country_code'])
                || strcasecmp((string) $term['country_code'], $countryCode) === 0;

            if ($matchesCountry) {
                $netTerms[] = (int) $term['net_term'];
            }
        }

        $netTerms = array_values(array_unique($netTerms));
        sort($netTerms);

        return $netTerms;
    }

    /**
     * The term to preselect: 30 days, or the available term closest to it, the shorter
     * one on a tie.
     *
     * @param int[] $netTerms
     */
    public function pickDefault(array $netTerms): ?int
    {
        if ($netTerms === []) {
            return null;
        }

        if (in_array(self::PREFERRED_NET_TERM, $netTerms, true)) {
            return self::PREFERRED_NET_TERM;
        }

        $closest = null;
        foreach ($netTerms as $netTerm) {
            if ($closest === null || abs($netTerm - self::PREFERRED_NET_TERM) < abs($closest - self::PREFERRED_NET_TERM)) {
                $closest = $netTerm;
            }
        }

        return $closest;
    }

    /**
     * @return array<int, array{net_term?: int, country_code?: string}>
     */
    private function getPaymentTerms(?string $salesChannelId): array
    {
        $config = $this->configService->setSalesChannelId($salesChannelId);
        $item = $this->cache->getItem(
            self::CACHE_KEY_PREFIX . md5(($salesChannelId ?? 'default') . '|' . $config->getApiToken() . '|' . (int) $config->isSandbox())
        );

        if ($item->isHit()) {
            return $item->get();
        }

        $terms = $this->monduClient
            ->setSalesChannelId($salesChannelId)
            ->getPaymentTerms(['source' => self::SOURCE_ASYNC]);

        // Do not cache a failed read, the next form load tries again.
        if ($terms === null) {
            return [];
        }

        $item->set($terms);
        $item->expiresAfter(self::CACHE_LIFETIME);
        $this->cache->save($item);

        return $terms;
    }
}
