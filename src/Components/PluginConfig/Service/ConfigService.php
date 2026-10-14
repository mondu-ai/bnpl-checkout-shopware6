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
    public const SANDBOX_API_URL = 'https://api.demo.mondu.ai/api/v1';

    /**
     * @var string|null
     */
    private ?string $salesChannelId = null;

    /**
     * @var bool|null
     */
    private ?bool $overrideSandbox = null;

    /**
     * @param  SystemConfigService  $systemConfigService
     * @param  EntityRepository     $pluginRepository
     */
    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $pluginRepository,
        private readonly EntityRepository $salesChannelRepository
    ) {}

    /**
     * @param $salesChannelId
     *
     * @return $this
     */
    public function setSalesChannelId($salesChannelId = null): static
    {
        $this->salesChannelId = $salesChannelId;

        return $this;
    }

    /**
     * @param $mode
     *
     * @return $this
     */
    public function setOverrideSandbox($mode): static
    {
        $this->overrideSandbox = $mode;

        return $this;
    }

    /**
     * @return bool|mixed|null
     */
    public function isSandbox(): mixed
    {
        if ($this->overrideSandbox !== null) {
            return $this->overrideSandbox;
        }

        $config = $this->getPluginConfiguration();

        return $config['sandbox'] ?? false;
    }

    /**
     * @return string
     */
    public function getBaseApiUrl(): string
    {
        return $this->isSandbox() ? self::SANDBOX_API_URL : self::API_URL;
    }

    /**
     * @param $url
     *
     * @return string
     */
    public function getApiUrl($url): string
    {
        return $this->getBaseApiUrl().'/'.$url;
    }

    /**
     * @return array|float|int|bool|string
     */
    public function getPluginConfiguration(): array|float|int|bool|string
    {
        return $this->systemConfigService->get('Mond1SW6.config', $this->salesChannelId) ?: [];
    }

    /**
     * @return array|bool|float|int|mixed[]|string
     */
    public function getPluginCustomConfiguration(): array|float|int|bool|string
    {
        return $this->systemConfigService->get('Mond1SW6.customConfig', $this->salesChannelId) ?: [];
    }

    /**
     * @return mixed|string|null
     */
    public function getApiToken(): mixed
    {
        $config = $this->getPluginConfiguration();

        return $config['apiToken'] ?? null;
    }

    /**
     * @return mixed|string|null
     */
    public function getWebhooksSecret(): mixed
    {
        $config = $this->getPluginCustomConfiguration();

        return $config['webhooksSecret'] ?? null;
    }

    public function getAllWebhooksSecrets(): array
    {
        $secrets = [];
        $prev = $this->salesChannelId;

        $collect = function (?string $salesChannelId) use (&$secrets): void {
            $this->salesChannelId = $salesChannelId;
            $s = $this->getWebhooksSecret();
            if (is_string($s) && $s !== '') {
                $secrets[$s] = true;
            }
        };

        $collect(null);

        $channels = $this->salesChannelRepository
            ->searchIds(new Criteria(), new Context(new SystemSource()))
            ->getIds();

        foreach ($channels as $scId) {
            if (is_string($scId)) {
                $collect($scId);
            }
        }

        $this->salesChannelId = $prev;

        return array_keys($secrets);
    }

    /**
     * @return false|mixed|string
     */
    public function getApiTokenValid(): mixed
    {
        $config = $this->getPluginCustomConfiguration();

        return $config['apiTokenValid'] ?? false;
    }

    /**
     * @return string
     */
    public function getSkipOrderStateValidationMode(): string
    {
        $config = $this->getPluginConfiguration();

        return $config['skipOrderStateValidation'] ?? 'no_skipping';
    }

    /**
     * @return bool
     */
    public function skipOrderStateValidation(): bool
    {
        return $this->getSkipOrderStateValidationMode() !== 'no_skipping';
    }

    /**
     * @return bool
     */
    public function isSkipAllValidationMode(): bool
    {
        return $this->getSkipOrderStateValidationMode() === 'skip_all_validation';
    }

    /**
     * @return bool
     */
    public function isSkippingAllMode(): bool
    {
        return $this->getSkipOrderStateValidationMode() === 'skipping_all';
    }

    /**
     * @return bool
     */
    public function isAutoTransitionOrderStateEnabled(): bool
    {
        $config = $this->getPluginConfiguration();

        return isset($config['autoTransitionOrderState']) && $config['autoTransitionOrderState'];
    }

    /**
     * @return bool
     */
    public function isExtendedLogsEnabled(): bool
    {
        $config = $this->getPluginConfiguration();

        return (bool) ($config['extendedLogs'] ?? false);
    }

    /**
     * @return string
     */
    public function getHandlingAddressAdditionalField1(): string
    {
        $config = $this->getPluginConfiguration();

        return $config['handlingAddressAdditionalField1'] ?? 'ignore';
    }

    /**
     * @return string
     */
    public function getHandlingAddressAdditionalField2(): string
    {
        $config = $this->getPluginConfiguration();

        return $config['handlingAddressAdditionalField2'] ?? 'ignore';
    }

    /**
     * Whether to hide Mondu payment methods for private (B2C) customers when account type selection is available.
     */
    /**
     * Whether the merchant switched on Mondu payment methods for orders created in the admin.
     */
    public function isBackendOrdersEnabled(): bool
    {
        $config = $this->getPluginConfiguration();

        return (bool) ($config['backendOrdersEnabled'] ?? false);
    }

    /**
     * Plugin setting holding the net terms a buyer may choose from, per Mondu payment method.
     */
    public const AVAILABLE_NET_TERMS_KEYS = [
        'invoice' => 'availableNetTermsInvoice',
        'direct_debit' => 'availableNetTermsDirectDebit',
        'pay_now' => 'availableNetTermsPayNow',
    ];

    /**
     * Net terms the merchant enabled for the storefront checkout of one Mondu payment method.
     * None means the checkout shows no term and Mondu applies the account default.
     *
     * @return int[]
     */
    public function getAvailableNetTerms(string $monduPaymentMethod): array
    {
        $key = self::AVAILABLE_NET_TERMS_KEYS[$monduPaymentMethod] ?? null;
        if ($key === null) {
            return [];
        }

        $configured = $this->getPluginConfiguration()[$key] ?? [];
        $netTerms = array_filter(array_map('intval', is_array($configured) ? $configured : []), static fn (int $days) => $days > 0);
        $netTerms = array_values(array_unique($netTerms));
        sort($netTerms);

        return $netTerms;
    }

    public function isHideMonduForB2CEnabled(): bool
    {
        $config = $this->getPluginConfiguration();

        return (bool) ($config['hideMonduForB2C'] ?? true);
    }

    /**
     * Whether the shop shows account type selection (private vs commercial) to customers.
     */
    public function isAccountTypeSelectionEnabled(): bool
    {
        return (bool) $this->systemConfigService->get('core.loginRegistration.showAccountTypeSelection', $this->salesChannelId);
    }

    /**
     * @param  string  $hash
     *
     * @return void
     */
    public function setWebhookRegistrationHash(string $hash): void
    {
        $this->systemConfigService->set('Mond1SW6.customConfig.webhookRegistrationHash', $hash, $this->salesChannelId);
    }

    /**
     * @param  string  $secret
     *
     * @return mixed
     */
    public function setWebhooksSecret(string $secret = '')
    {
        return $this->systemConfigService->set('Mond1SW6.customConfig.webhooksSecret', $secret, $this->salesChannelId);
    }

    /**
     * @param  bool  $val
     *
     * @return void
     */
    public function setIsApiTokenValid(bool $val = false): void
    {
        $this->systemConfigService->set('Mond1SW6.customConfig.apiTokenValid', $val, $this->salesChannelId);
    }

    /**
     * @return bool
     */
    public function isStateWatchingEnabled(): bool
    {
        $config = $this->getPluginConfiguration();

        return isset($config['stateEnabled']) && $config['stateEnabled'];
    }

    /**
     * @return mixed
     */
    public function getPluginVersion()
    {
        return $this->getPlugin()->getVersion();
    }

    /**
     * @return mixed|string
     */
    public function orderTransactionState(): mixed
    {
        $config = $this->getPluginConfiguration();

        return $config['orderTransactionState'] ?? 'paid';
    }

    /**
     * @return mixed
     */
    public function getPluginName()
    {
        return $this->getPlugin()->getName();
    }

    /**
     * @return \Shopware\Core\Framework\DataAbstractionLayer\Entity|null
     */
    public function getPlugin()
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'Mond1SW6'));

        return $this->pluginRepository->search($criteria, new Context(new SystemSource()))->first();
    }
}
