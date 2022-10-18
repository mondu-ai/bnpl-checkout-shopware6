<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PluginConfig\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigService
{
    public const API_URL = 'https://api.mondu.ai/api/v1';
    public const WIDGET_URL = 'https://checkout.mondu.ai/widget.js';
    public const SANDBOX_API_URL = 'https://api.demo.mondu.ai/api/v1';
    public const SANDBOX_WIDGET_URL = 'https://checkout.demo.mondu.ai/widget.js';
    public const LOCAL_API_URL = 'http://host.docker.internal:3000/api/v1';
    public const LOCAL_WIDGET_URL = 'http://localhost:3002/widget.js';
    /**
     * @var SystemConfigService
     */
    private SystemConfigService $systemConfigService;
    private ?string $salesChannelId = null;

    /**
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(SystemConfigService $systemConfigService)
    {
        $this->systemConfigService = $systemConfigService;
    }

    public function setSalesChannelId($salesChannelId = null)
    {
        $this->salesChannelId = $salesChannelId;

        return $this;
    }

    public function isSandbox()
    {
        $config = $this->getPluginConfiguration();

        return isset($config['sandbox']) ? $config['sandbox'] : false;
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
}
