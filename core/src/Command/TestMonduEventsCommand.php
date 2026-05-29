<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestMonduEventsCommand extends Command
{
    protected static $defaultName = 'mondu:test:events';
    protected static $defaultDescription = 'Test Mondu custom events';

    protected function configure(): void
    {
        $this
            ->setName('mondu:test:events')
            ->setDescription('Test Mondu custom events');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Testing Mondu Custom Events...');
        $output->writeln('Available events:');
        $output->writeln('- mondu.order.confirmed');
        $output->writeln('- mondu.order.cancelled');
        $output->writeln('- mondu.order.pending');
        $output->writeln('- mondu.order.approved');
        $output->writeln('- mondu.order.declined');
        $output->writeln('');
        $output->writeln('Events are triggered automatically when webhooks are received from Mondu.');
        $output->writeln('Check the Mondu logs for event details in var/log/mondu.log');

        return Command::SUCCESS;
    }
}