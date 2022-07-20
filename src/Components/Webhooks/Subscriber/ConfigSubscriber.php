<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Webhooks\Subscriber;

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

    public function __construct(WebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
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

            if ($changeSet != null
                && $changeSet->hasChanged('configuration_value')
                && $changeSet->getBefore('configuration_key') == 'MonduPayment.config.apiToken') {  
                    $this->webhookService->register($event->getContext());
            }

        }
    }   
}
