<?php
declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PluginConfig\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigService {
    const API_URL = 'https://api.mondu.ai/api/v1';
    const WIDGET_URL = 'https://checkout.mondu.ai/widget.js';
    const SANDBOX_API_URL = 'https://api.demo.mondu.ai/api/v1';
    const SANDBOX_WIDGET_URL = 'https://checkout.demo.mondu.ai/widget.js';
    const LOCAL_API_URL = 'http://host.docker.internal:3000/api/v1';
    const LOCAL_WIDGET_URL = 'http://localhost:3002/widget.js';
    /**
     * @var SystemConfigService
     */
    private SystemConfigService $systemConfigService;

    /**
     * @param SystemConfigService $systemConfigService
     */
    public function __construct(SystemConfigService $systemConfigService) {
        $this->systemConfigService = $systemConfigService;
    }

    public function isSandbox() {
        $config = $this->getPluginConfiguration();

        return @$config['sandbox'] ?? false;
    }

    public function getBaseApiUrl(): string
    {
        return $this->isSandbox() ? self::SANDBOX_API_URL : self::API_URL;
    }

    public function getWidgetUrl() {
        return $this->isSandbox() ? self::SANDBOX_WIDGET_URL : self::WIDGET_URL;
    }

    public function getApiUrl($url): string
    {
        return $this->getBaseApiUrl().'/'.$url;
    }

    public function getPluginConfiguration() {
        return $this->systemConfigService->get('MonduPayment.config') ?: [];
    }

    public function getApiToken() {
        $config = $this->getPluginConfiguration();

        return $config['apiToken'] ?? null;
    }

    public function getWebhooksSecret() {
      $config = $this->getPluginConfiguration();

      return $config['webhooksSecret'] ?? null;
    }

    public function isStateWatchingEnabled(): bool {
        $config = $this->getPluginConfiguration();

        return isset($config['stateEnabled']) && $config['stateEnabled'];
    }
}