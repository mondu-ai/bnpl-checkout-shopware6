<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\AsyncOrder\Service;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Net terms the merchant may offer, read from GET /api/v1/payment_terms.
 *
 * The read is always scoped to one order source. Without it the API merges the terms
 * of every order source the merchant has, and a form would offer a term the merchant
 * only holds for another flow, which Mondu then refuses with "net term not available".
 * The payment method narrows it further: the terms differ per method, and order
 * creation validates the term against the source, the method and the buyer's country.
 * The country is narrowed here, the same way the Magento module does it.
 */
class PaymentTermsService
{
    /**
     * Orders created in the admin, through POST /orders/create_async.
     */
    public const SOURCE_ASYNC = 'async';

    /**
     * Storefront checkout. The API has no separate "hosted" source: a hosted checkout
     * order is looked up as a widget order.
     */
    public const SOURCE_WIDGET = 'widget';

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
     * @param string $source One of SOURCE_ASYNC, SOURCE_WIDGET
     * @param string|null $paymentMethod Mondu payment method, e.g. "invoice"
     *
     * @return int[]
     */
    public function getNetTerms(
        ?string $salesChannelId,
        ?string $countryCode,
        string $source = self::SOURCE_ASYNC,
        ?string $paymentMethod = null
    ): array {
        $netTerms = [];

        foreach ($this->getPaymentTerms($salesChannelId, $source, $paymentMethod) ?? [] as $term) {
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
     * Sorted, unique net terms the merchant holds for checkout orders with this method, in
     * any country. These are the terms the plugin config offers to choose from.
     *
     * @return int[]|null null when Mondu could not be asked
     */
    public function getCheckoutNetTermOptions(?string $salesChannelId, string $paymentMethod): ?array
    {
        $terms = $this->getPaymentTerms($salesChannelId, self::SOURCE_WIDGET, $paymentMethod);
        if ($terms === null) {
            return null;
        }

        return $this->getNetTerms($salesChannelId, null, self::SOURCE_WIDGET, $paymentMethod);
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
     * @return array<int, array{net_term?: int, country_code?: string}>|null null when the read failed
     */
    private function getPaymentTerms(?string $salesChannelId, string $source, ?string $paymentMethod): ?array
    {
        $config = $this->configService->setSalesChannelId($salesChannelId);
        // The source and the method are part of the key: the admin and the storefront read
        // the same endpoint, and their answers must not overwrite each other.
        $item = $this->cache->getItem(self::CACHE_KEY_PREFIX . md5(implode('|', [
            $salesChannelId ?? 'default',
            $config->getApiToken(),
            (int) $config->isSandbox(),
            $source,
            $paymentMethod ?? 'any',
        ])));

        if ($item->isHit()) {
            return $item->get();
        }

        $filters = ['source' => $source];
        if ($paymentMethod !== null && $paymentMethod !== '') {
            $filters['payment_method'] = $paymentMethod;
        }

        $terms = $this->monduClient
            ->setSalesChannelId($salesChannelId)
            ->getPaymentTerms($filters);

        // Do not cache a failed read, the next form load tries again.
        if ($terms === null) {
            return null;
        }

        $item->set($terms);
        $item->expiresAfter(self::CACHE_LIFETIME);
        $this->cache->save($item);

        return $terms;
    }
}
