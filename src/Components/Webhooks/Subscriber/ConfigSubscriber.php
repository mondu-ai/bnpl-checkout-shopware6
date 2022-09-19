<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Webhooks\Subscriber;

use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Components\StateMachine\Exception\MonduException;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\System\SystemConfig\SystemConfigDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Validation\PreWriteValidationEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\ChangeSetAware;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\InsertCommand;
use Shopware\Core\Framework\DataAbstractionLayer\Write\Command\UpdateCommand;
use Mondu\MonduPayment\Components\Webhooks\Service\WebhookService;

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
        // Return the events to listen to as array like this:  <event to listen to> => <method to execute>

        return [
            PreWriteValidationEvent::class => 'triggerChangeSet',
            'system_config.written' => 'onSystemConfigWritten',
        ];
    }

    public function triggerChangeSet(PreWriteValidationEvent $event): void
    {
        foreach ($event->getCommands() as $command) {
            if (!$command instanceof ChangeSetAware) {
                continue;
            }

            /** @var ChangeSetAware|InsertCommand|UpdateCommand $command */
            if ($command->getDefinition()->getEntityName() !== SystemConfigDefinition::ENTITY_NAME) {
                continue;
            }

            $command->requestChangeSet();
        }
    }

    public function onSystemConfigWritten(EntityWrittenEvent $event): void
    {

        foreach ($event->getWriteResults() as $result) {
            $changeSet = $result->getChangeSet();

            if ($result->getProperty('configurationKey') == 'Mond1SW6.config.apiToken')
            {  
                $key = $result->getProperty('configurationValue');

                $isApiTokenValid = !!$this->webhookService->getSecret($key, $event->getContext());

                if(!$isApiTokenValid) {
                    $this->configService->setIsApiTokenValid(false);
                    throw new MonduException('Invalid api key');
                }
                $this->configService->setIsApiTokenValid(true);

                $this->webhookService->register($event->getContext());
            }

        }
    }   
}
