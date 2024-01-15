<?php declare(strict_types=1);

namespace Mondu\MonduPayment\Command;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Activate Payment Command
 */
class ActivatePaymentCommand extends Command
{
    protected static $defaultName = 'Mond1SW6:Activate:Payment';
    private Context $context;
    private EntityRepository $salesChannelRepository;
    private EntityRepository $paymentMethodRepository;
    private EntityRepository $salesChannelPaymentMethodRepository;

    /**
     * @param EntityRepository $salesChannelPaymentMethodRepository
     * @param EntityRepository $paymentMethodRepository
     * @param EntityRepository $salesChannelRepository
     */
    public function __construct(
        EntityRepository $salesChannelPaymentMethodRepository,
        EntityRepository $paymentMethodRepository,
        EntityRepository $salesChannelRepository
    ) {
        parent::__construct();

        $this->context = Context::createDefaultContext();
        $this->salesChannelPaymentMethodRepository = $salesChannelPaymentMethodRepository;
        $this->paymentMethodRepository = $paymentMethodRepository;
        $this->salesChannelRepository = $salesChannelRepository;
    }

    protected function configure(): void
    {
        $this->setDescription('Adds Mondu Payment methods to the sales channels.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $salesChannels = $this->salesChannelRepository->search(new Criteria(), $this->context);

        foreach ($salesChannels->getIterator() as $salesChannel) {

            $criteria = new Criteria();
            $criteria->addFilter(new ContainsFilter('handlerIdentifier', 'MonduPayment'));
            $paymentMethods = $this->paymentMethodRepository->search($criteria, $this->context);

            foreach ($paymentMethods->getIterator() as $paymentMethod) {
                $this->salesChannelPaymentMethodRepository->create([
                    [
                        'salesChannelId'  => $salesChannel->getId(),
                        'paymentMethodId' => $paymentMethod->getId()
                    ]
                ], $this->context);
            }
        }
        
        $output->writeln("Mondu payment methods are activated.\n");

        return 0;
    }
}
