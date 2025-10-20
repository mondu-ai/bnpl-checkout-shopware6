<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Webhooks\Subscriber;

use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mondu\MonduPayment\Components\Webhooks\Service\WebhookService;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;

class ConfigSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WebhookService $webhookService,
        private readonly ConfigService $configService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'onSystemConfigWritten',
        ];
    }

    public function onSystemConfigWritten(SystemConfigChangedEvent $event): void
    {
        $salesChannelId = $event->getSalesChannelId();
        
        // Handle API Token change
        if ($event->getKey() == 'Mond1SW6.config.apiToken') {
            $value = $event->getValue();

            $isApiTokenValid = !!$this->webhookService->setSalesChannelId($salesChannelId)->getSecret($value);

            if(!$isApiTokenValid) {
                $this->configService->setSalesChannelId($salesChannelId)->setIsApiTokenValid(false);
            } else {
                $this->configService->setSalesChannelId($salesChannelId)->setIsApiTokenValid(true);
                
                // Check if we need to register webhooks
                $shouldRegisterWebhooks = $this->shouldRegisterWebhooks($salesChannelId);
                
                if ($shouldRegisterWebhooks) {
                    $this->webhookService->setSalesChannelId($salesChannelId)->register();
                    $this->saveWebhookRegistrationHash($salesChannelId);
                }
            }
        }
        
        // Handle Sandbox mode change
        if ($event->getKey() == 'Mond1SW6.config.sandbox') {
            // Get current API token
            $apiToken = $this->configService->setSalesChannelId($salesChannelId)->getApiToken();
            
            // Only register webhooks if API token exists and is valid
            if ($apiToken) {
                $isApiTokenValid = !!$this->webhookService->setSalesChannelId($salesChannelId)->getSecret($apiToken);
                
                if ($isApiTokenValid) {
                    $this->configService->setSalesChannelId($salesChannelId)->setIsApiTokenValid(true);
                    
                    // Check if we need to register webhooks
                    $shouldRegisterWebhooks = $this->shouldRegisterWebhooks($salesChannelId);
                    
                    if ($shouldRegisterWebhooks) {
                        $this->webhookService->setSalesChannelId($salesChannelId)->register();
                        $this->saveWebhookRegistrationHash($salesChannelId);
                    }
                }
            }
        }
    }
    
    /**
     * Check if webhooks need to be registered based on configuration hash
     */
    private function shouldRegisterWebhooks(?string $salesChannelId): bool
    {
        $config = $this->configService->setSalesChannelId($salesChannelId);
        $currentHash = $this->getConfigurationHash($salesChannelId);
        $savedHash = $config->getPluginCustomConfiguration()['webhookRegistrationHash'] ?? null;
        
        // Register if hash changed or doesn't exist
        return $currentHash !== $savedHash;
    }
    
    /**
     * Generate hash from API token and sandbox mode
     */
    private function getConfigurationHash(?string $salesChannelId): string
    {
        $config = $this->configService->setSalesChannelId($salesChannelId)->getPluginConfiguration();
        $apiToken = $config['apiToken'] ?? '';
        $sandbox = $config['sandbox'] ?? false;
        
        return md5($apiToken . '_' . ($sandbox ? '1' : '0'));
    }
    
    /**
     * Save webhook registration hash to prevent duplicate registrations
     */
    private function saveWebhookRegistrationHash(?string $salesChannelId): void
    {
        $hash = $this->getConfigurationHash($salesChannelId);
        $this->configService->setSalesChannelId($salesChannelId)
            ->setWebhookRegistrationHash($hash);
    }   
}

