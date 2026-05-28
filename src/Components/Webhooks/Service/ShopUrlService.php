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
        if ($salesChannelId) {
            $scUrl = $this->getSalesChannelUrl($salesChannelId);
            if ($scUrl !== '') {
                return $scUrl;
            }
        }

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

        return '';
    }
}
