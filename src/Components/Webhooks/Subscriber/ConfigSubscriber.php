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
        if ($event->getKey() == 'Mond1SW6.config.apiToken') {
            $value = $event->getValue();
            $salesChannelId = $event->getSalesChannelId();

            $isApiTokenValid = !!$this->webhookService->setSalesChannelId($salesChannelId)->getSecret($value);

            if(!$isApiTokenValid) {
                $this->configService->setSalesChannelId($salesChannelId)->setIsApiTokenValid(false);
            } else {
                $this->configService->setSalesChannelId($salesChannelId)->setIsApiTokenValid(true);
                $this->webhookService->setSalesChannelId($salesChannelId)->register();    
            }
        }
    }   
}
