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

#[AsCommand(name: 'mondu:check-flow-aware', description: 'Check Flow Builder aware interfaces for Mondu events')]
class CheckFlowAwareCommand extends Command
{
    public function __construct(
        private readonly BusinessEventCollector $businessEventCollector
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $events = $this->businessEventCollector->collect($context);

        $io->title('Flow Builder Events Analysis');

        $monduEvents = [];
        foreach ($events as $event) {
            if (strpos($event->getName(), 'mondu') !== false || strpos($event->getName(), 'Mondu') !== false) {
                $monduEvents[] = $event;
            }
        }

        if (empty($monduEvents)) {
            $io->error('No Mondu events found!');
            return Command::FAILURE;
        }

        $io->success(sprintf('Found %d Mondu events', count($monduEvents)));

        foreach ($monduEvents as $event) {
            $io->section($event->getName());

            $io->writeln('Class: ' . $event->getClass());

            try {
                $reflection = new \ReflectionClass($event);
                $property = $reflection->getProperty('aware');
                $property->setAccessible(true);
                $aware = $property->getValue($event);

                if (empty($aware)) {
                    $io->error('No aware interfaces defined in BusinessEventDefinition!');
                } else {
                    $io->writeln('Aware interfaces (from definition):');
                    foreach ($aware as $interface) {
                        $io->writeln('  - ' . $interface);
                    }
                }
            } catch (\Exception $e) {
                $io->warning('Could not read aware property: ' . $e->getMessage());
            }

            $class = $event->getClass();
            if (class_exists($class)) {
                $reflection = new \ReflectionClass($class);
                $interfaces = $reflection->getInterfaceNames();

                $io->writeln('');
                $io->writeln('Actual class interfaces:');
                foreach ($interfaces as $interface) {
                    $shortName = substr($interface, strrpos($interface, '\\') + 1);
                    $io->writeln('  - ' . $shortName);
                }
            }

            $io->newLine();
        }

        $io->section('Comparison: checkout.order.placed');
        foreach ($events as $event) {
            if ($event->getName() === 'checkout.order.placed') {
                $io->writeln('Class: ' . $event->getClass());

                try {
                    $reflection = new \ReflectionClass($event);
                    $property = $reflection->getProperty('aware');
                    $property->setAccessible(true);
                    $aware = $property->getValue($event);

                    $io->writeln('Aware interfaces:');
                    foreach ($aware as $interface) {
                        $io->writeln('  - ' . $interface);
                    }
                } catch (\Exception $e) {
                    $io->warning('Could not read aware property: ' . $e->getMessage());
                }
                break;
            }
        }

        return Command::SUCCESS;
    }
}
