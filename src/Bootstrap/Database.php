<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Bootstrap;

use Mondu\MonduPayment\Util\MigrationHelper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
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
        $this->connection->{$method === 'executeStatement' ? $method : 'exec'}('SET FOREIGN_KEY_CHECKS=0;');
        $this->connection->{$method}('DROP TABLE IF EXISTS `mondu_order_data`');
        $this->connection->{$method}('DROP TABLE IF EXISTS `mondu_invoice_data`');
        $this->connection->{$method === 'executeStatement' ? $method : 'exec'}('SET FOREIGN_KEY_CHECKS=1;');

        //Search for config keys that contain the bundle's name
        /** @var EntityRepositoryInterface $systemConfigRepository */
        $systemConfigRepository = $this->container->get('system_config.repository');
        $criteria = (new Criteria())
            ->addFilter(
                new ContainsFilter('configurationKey', 'MonduPayment.config')
            );
        $idSearchResult = $systemConfigRepository->searchIds($criteria, Context::createDefaultContext());

        //Formatting IDs array and deleting config keys
        $ids = \array_map(static function ($id) {
            return ['id' => $id];
        }, $idSearchResult->getIds());
        $systemConfigRepository->delete($ids, Context::createDefaultContext());
    }

    public function activate(): void
    {
    }

    public function deactivate(): void
    {
    }
}
