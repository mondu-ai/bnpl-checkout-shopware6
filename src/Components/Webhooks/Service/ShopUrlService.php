<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Webhooks\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class ShopUrlService
{
    public function __construct(
        private readonly EntityRepository $salesChannelRepository,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Get shop URL for the given sales channel ID
     */
    public function getShopUrl(?string $salesChannelId = null): string
    {
        // If we're in a web context, try to get URL from $_SERVER first
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            return $_SERVER['HTTP_ORIGIN'];
        }

        if (isset($_SERVER['HTTP_HOST'])) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            return $protocol . '://' . $_SERVER['HTTP_HOST'];
        }

        // Fallback: get URL from sales channel configuration
        if ($salesChannelId) {
            return $this->getSalesChannelUrl($salesChannelId);
        }

        // Final fallback: get URL from default sales channel
        return $this->getDefaultSalesChannelUrl();
    }

    /**
     * Get URL from specific sales channel
     */
    private function getSalesChannelUrl(string $salesChannelId): string
    {
        $context = Context::createDefaultContext();
        $criteria = new Criteria([$salesChannelId]);
        $criteria->addAssociation('domains');

        /** @var SalesChannelEntity|null $salesChannel */
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        if ($salesChannel && $salesChannel->getDomains()->count() > 0) {
            $domain = $salesChannel->getDomains()->first();
            $url = $domain->getUrl();

            // Ensure URL has protocol
            if (!preg_match('/^https?:\/\//', $url)) {
                $url = 'https://' . $url;
            }

            return $url;
        }

        return $this->getDefaultSalesChannelUrl();
    }

    /**
     * Get URL from default sales channel
     */
    private function getDefaultSalesChannelUrl(): string
    {
        $context = Context::createDefaultContext();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addAssociation('domains');
        $criteria->setLimit(1);

        /** @var SalesChannelEntity|null $salesChannel */
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();

        if ($salesChannel && $salesChannel->getDomains()->count() > 0) {
            $domain = $salesChannel->getDomains()->first();
            $url = $domain->getUrl();

            // Ensure URL has protocol
            if (!preg_match('/^https?:\/\//', $url)) {
                $url = 'https://' . $url;
            }

            return $url;
        }

        $this->logger->error('mondu.ERROR: ShopUrlService could not determine shop URL — no sales channel domain found, no HTTP_HOST available');
        return '';
    }
}
