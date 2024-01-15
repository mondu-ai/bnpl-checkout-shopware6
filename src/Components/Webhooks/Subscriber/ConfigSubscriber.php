<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Webhooks\Subscriber;

use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mondu\MonduPayment\Components\Webhooks\Service\WebhookService;
use Shopware\Core\System\SystemConfig\Event\SystemConfigChangedEvent;

/**
 * Config Subscriber Class
 */
class ConfigSubscriber implements EventSubscriberInterface
{
    private WebhookService $webhookService;
    private ConfigService $configService;

    public function __construct(WebhookService $webhookService, ConfigService $configService)
    {
        $this->webhookService = $webhookService;
        $this->configService = $configService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigChangedEvent::class => 'onSystemConfigWritten',
        ];
    }

    public function onSystemConfigWritten(SystemConfigChangedEvent $event): void
    {

        if ($event->getKey() == 'Mond1SW6.config.apiToken')
        {  
            $value = $event->getValue();
            $salesChannelId = $event->getSalesChannelId();

            $isApiTokenValid = !!$this->webhookService->setSalesChannelId($salesChannelId)->getSecret($value);

            if(!$isApiTokenValid) {
                $this->configService->setSalesChannelId($salesChannelId)->setIsApiTokenValid();
            } else {
                $this->configService->setSalesChannelId($salesChannelId)->setIsApiTokenValid(true);
                $this->webhookService->setSalesChannelId($salesChannelId)->register();    
            }
        }
    }   
}
