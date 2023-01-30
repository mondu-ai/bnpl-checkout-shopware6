<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Command;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class TestApiTokenCommand extends Command
{
    protected static $defaultName = 'Mond1SW6:Test';
    private MonduClient $monduClient;

    public function __construct(
        MonduClient $monduClient
    )
    {
        parent::__construct();

        $this->monduClient = $monduClient;
    }

    protected function configure(): void
    {
        $this->setDescription('Tests if API token is valid.');
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
        $api_token = (string) $input->getArgument('api_token');
        $sandboxMode = boolval($input->getArgument('sandbox_mode'));

        $response = $this->monduClient->getWebhooksSecret($api_token, $sandboxMode);

        if ($response == null) {
            throw new \ErrorException("API token is not valid");
        }

        echo "Api token is valid\n";

        return 0;
    }
}