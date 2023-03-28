<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Bootstrap;

use Mondu\MonduPayment\Util\MigrationHelper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\Context;

class Database extends AbstractBootstrap
{
    /**
     * @var Connection
     */
    protected $connection;

    public function injectServices(): void
    {
        $this->connection = $this->container->get(Connection::class);
    }

    public function install(): void
    {
    }

    public function update(): void
    {
    }

    /**
     * @throws DBALException
     */
    public function uninstall(bool $keepUserData = false): void
    {
        if ($keepUserData) {
            return;
        }

        $method = MigrationHelper::getExecuteStatementMethod();
        $this->connection->{$method}('DROP TABLE IF EXISTS `mondu_order_data`');
        $this->connection->{$method}('DROP TABLE IF EXISTS `mondu_invoice_data`');

        //Search for config keys that contain the bundle's name
        /** @var EntityRepositoryInterface $systemConfigRepository */
        $systemConfigRepository = $this->container->get('system_config.repository');
        $criteria = new Criteria();
        $criteria->addFilter(
            new MultiFilter(
                MultiFilter::CONNECTION_OR,
                [
                    new ContainsFilter('configurationKey', 'Mond1SW6.config'),
                    new ContainsFilter('configurationKey', 'Mond1SW6.customConfig')
                ]
            )
        );
        $idSearchResult = $systemConfigRepository->searchIds($criteria, $this->context);

        //Formatting IDs array and deleting config keys
        $ids = \array_map(static function ($id) {
            return ['id' => $id];
        }, $idSearchResult->getIds());
        $systemConfigRepository->delete($ids, $this->context);
    }

    public function activate(): void
    {
    }

    public function deactivate(): void
    {
    }
}
