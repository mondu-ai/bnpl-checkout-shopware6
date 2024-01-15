<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PluginConfig\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Api\Context\SystemSource;

class ConfigService
{
    public const API_URL = 'https://api.mondu.ai/api/v1';
    public const WIDGET_URL = 'https://checkout.mondu.ai/widget.js';
    public const SANDBOX_API_URL = 'https://api.demo.mondu.ai/api/v1';
    public const SANDBOX_WIDGET_URL = 'https://checkout.demo.mondu.ai/widget.js';

    /**
     * @var SystemConfigService
     */
    private SystemConfigService $systemConfigService;
    private ?string $salesChannelId = null;
    private ?bool $overrideSandbox = null;
    private EntityRepository $pluginRepository;

    /**
     * @param SystemConfigService $systemConfigService
     * @param EntityRepository $pluginRepository
     */
    public function __construct(SystemConfigService $systemConfigService, EntityRepository $pluginRepository)
    {
        $this->systemConfigService = $systemConfigService;
        $this->pluginRepository = $pluginRepository;
    }

    public function setSalesChannelId($salesChannelId = null)
    {
        $this->salesChannelId = $salesChannelId;

        return $this;
    }

    public function setOverrideSandbox($mode)
    {
        $this->overrideSandbox = $mode;

        return $this;
    }

    public function isSandbox()
    {
        if (!is_null($this->overrideSandbox)) {
            return $this->overrideSandbox;
        }

        $config = $this->getPluginConfiguration();

        return $config['sandbox'] ?? false;
    }

    public function getBaseApiUrl(): string
    {
        return $this->isSandbox() ? self::SANDBOX_API_URL : self::API_URL;
    }

    public function getWidgetUrl()
    {
        return $this->isSandbox() ? self::SANDBOX_WIDGET_URL : self::WIDGET_URL;
    }

    public function getApiUrl($url): string
    {
        return $this->getBaseApiUrl().'/'.$url;
    }

    public function getPluginConfiguration()
    {
        return $this->systemConfigService->get('Mond1SW6.config', $this->salesChannelId) ?: [];
    }

    public function getPluginCustomConfiguration()
    {
        return $this->systemConfigService->get('Mond1SW6.customConfig', $this->salesChannelId) ?: [];
    }

    public function getApiToken()
    {
        $config = $this->getPluginConfiguration();

        return $config['apiToken'] ?? null;
    }

    public function getWebhooksSecret()
    {
        $config = $this->getPluginCustomConfiguration();

        return $config['webhooksSecret'] ?? null;
    }

    public function getApiTokenValid()
    {
        $config = $this->getPluginCustomConfiguration();

        return $config['apiTokenValid'] ?? false;
    }

    public function skipOrderStateValidation()
    {
        $config = $this->getPluginConfiguration();

        return $config['skipOrderStateValidation'] ?? false;
    }

    public function setWebhooksSecret($secret = '')
    {
        return $this->systemConfigService->set('Mond1SW6.customConfig.webhooksSecret', $secret, $this->salesChannelId);
    }

    public function setIsApiTokenValid(bool $val = false)
    {
        $this->systemConfigService->set('Mond1SW6.customConfig.apiTokenValid', $val, $this->salesChannelId);
    }

    public function isStateWatchingEnabled(): bool
    {
        $config = $this->getPluginConfiguration();

        return isset($config['stateEnabled']) && $config['stateEnabled'];
    }

    public function getPluginVersion()
    {
        return $this->getPlugin()->getVersion();
    }

    public function orderTransactionState()
    {
        $config = $this->getPluginConfiguration();

        return $config['orderTransactionState'] ?? 'paid';
    }

    public function getPluginName()
    {
        return $this->getPlugin()->getName();
    }

    public function getPlugin()
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'Mond1SW6'));

        return $this->pluginRepository->search($criteria, new Context(new SystemSource()))->first();
    }
}
