<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\BusinessEventCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mondu:check-events',
    description: 'Check if Mondu events are registered in Flow Builder'
)]
class CheckMonduEventsCommand extends Command
{
    public function __construct(
        private readonly BusinessEventCollector $businessEventCollector
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Checking Mondu Events Registration in Flow Builder');

        $context = Context::createDefaultContext();
        $events = $this->businessEventCollector->collect($context);

        $monduEvents = [];
        foreach ($events->getElements() as $event) {
            if (str_starts_with($event->getName(), 'mondu.')) {
                $monduEvents[] = $event->getName();
            }
        }

        if (empty($monduEvents)) {
            $io->error('No Mondu events found in Flow Builder!');
            $io->note('Expected events: mondu.order.confirmed, mondu.order.cancelled, etc.');
            return Command::FAILURE;
        }

        $io->success(sprintf('Found %d Mondu event(s):', count($monduEvents)));
        $io->listing($monduEvents);

        return Command::SUCCESS;
    }
}

