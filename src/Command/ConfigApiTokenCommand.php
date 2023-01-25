<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Command;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigApiTokenCommand extends Command
{
    protected static $defaultName = 'Mond1SW6:Config:ApiToken';

    private SystemConfigService $systemConfig;

    public function __construct(
        SystemConfigService $systemConfig
    ){
        parent::__construct();
    
        $this->systemConfig = $systemConfig;
    }

    protected function configure(): void
    {
        $this->setDescription('Adds API token to plugin configuration.');
        $this->addArgument(
            'api_token',
            InputArgument::REQUIRED,
            'Merchant\'s API token'
        );
        $this->addArgument(
            'sandbox_mode',
            InputArgument::REQUIRED,
            'Merchant\'s API token'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $api_token = (string)$input->getArgument('api_token');
        $sandboxMode = boolval($input->getArgument('sandbox_mode'));

        error_reporting(0);

        $this->systemConfig->set("Mond1SW6.config.apiToken", $api_token, null);
        $this->systemConfig->set("Mond1SW6.config.sandbox", $sandboxMode, null);

        echo "Api token successfully updated\n";

        return 0;
    }
}