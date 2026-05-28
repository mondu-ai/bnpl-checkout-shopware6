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
     * Get shop URL for the given sales channel ID.
     *
     * When a sales channel id is given, the sales-channel domain wins over the
     * current HTTP_HOST. In the admin context (saving config per sales channel)
     * HTTP_HOST points to the admin URL, not to the storefront of that channel —
     * auto-registering a webhook with that URL would produce a wrong address
     * (e.g. main URL for a French channel that should be mounted under /fr).
     */
    public function getShopUrl(?string $salesChannelId = null): string
    {
        if ($salesChannelId) {
            $scUrl = $this->getSalesChannelUrl($salesChannelId);
            if ($scUrl !== '') {
                return $scUrl;
            }
        }

        return $this->getDefaultSalesChannelUrl();
    }

    /**
     * Get URL from specific sales channel. Returns '' if the channel has no domain.
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
            $url = rtrim((string) $domain->getUrl(), '/');

            if ($url === '') {
                return '';
            }

            if (!preg_match('/^https?:\/\//', $url)) {
                $url = 'https://' . $url;
            }

            return $url;
        }

        return '';
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
