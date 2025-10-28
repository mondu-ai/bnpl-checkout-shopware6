<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\BusinessEventCollector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class CheckFlowAwareCommand extends Command
{
    protected static $defaultName = 'mondu:check-flow-aware';

    private BusinessEventCollector $businessEventCollector;

    public function __construct(BusinessEventCollector $businessEventCollector)
    {
        parent::__construct();
        $this->businessEventCollector = $businessEventCollector;
    }

    public static function getDefaultName(): ?string
    {
        return 'mondu:check-flow-aware';
    }

    protected function configure(): void
    {
        $this->setName('mondu:check-flow-aware')
             ->setDescription('Check Flow Builder aware interfaces for Mondu events');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        // Collect all business events
        $events = $this->businessEventCollector->collect($context);

        $io->title('Flow Builder Events Analysis');

        // Filter Mondu events
        $monduEvents = [];
        foreach ($events as $event) {
            if (strpos($event->getName(), 'mondu') !== false) {
                $monduEvents[] = $event;
            }
        }

        if (empty($monduEvents)) {
            $io->error('❌ No Mondu events found!');
            return Command::FAILURE;
        }

        $io->success(sprintf('✅ Found %d Mondu events', count($monduEvents)));

        foreach ($monduEvents as $event) {
            $io->section($event->getName());
            
            $io->writeln('Class: ' . $event->getClass());
            
            // Get aware interfaces - In Shopware 6.6, getAware() might need a context
            try {
                // Try with reflection to see what's stored
                $reflection = new \ReflectionClass($event);
                $property = $reflection->getProperty('aware');
                $property->setAccessible(true);
                $aware = $property->getValue($event);
                
                if (empty($aware)) {
                    $io->error('❌ No aware interfaces defined in BusinessEventDefinition!');
                } else {
                    $io->writeln('Aware interfaces (from definition):');
                    foreach ($aware as $interface) {
                        $io->writeln('  - ' . $interface);
                    }
                }
            } catch (\Exception $e) {
                $io->warning('Could not read aware property: ' . $e->getMessage());
            }

            // Check actual class interfaces
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

        // Now check what the default Checkout Order Placed event looks like
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

