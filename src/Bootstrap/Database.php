<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Bootstrap;

use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderStates;
use Mondu\MonduPayment\Util\MigrationHelper;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;

class Database extends AbstractBootstrap
{
    /**
     * @var Connection
     */
    protected Connection $connection;

    /**
     * @return void
     */
    public function injectServices(): void
    {
        $this->connection = $this->container->get(Connection::class);
    }

    /**
     * @return void
     */
    public function install(): void
    {
    }

    /**
     * @return void
     */
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
        $this->removeAsyncOrderStates();
        $this->connection->{$method}('DROP TABLE IF EXISTS `mondu_order_data`');
        $this->connection->{$method}('DROP TABLE IF EXISTS `mondu_invoice_data`');

        //Search for config keys that contain the bundle's name
        /** @var EntityRepository $systemConfigRepository */
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

    /**
     * Removes the payment states added for orders created in the admin, with their
     * transitions. A state some order transaction or its history still points to stays,
     * so that no order loses its state.
     */
    private function removeAsyncOrderStates(): void
    {
        $states = $this->connection->fetchFirstColumn(
            'SELECT state.id FROM state_machine_state state
             JOIN state_machine machine ON machine.id = state.state_machine_id
             WHERE machine.technical_name = :machine AND state.technical_name IN (:states)',
            ['machine' => 'order_transaction.state', 'states' => AsyncOrderStates::STATES],
            ['states' => ArrayParameterType::STRING]
        );

        foreach ($states as $stateId) {
            $inUse = $this->connection->fetchOne(
                'SELECT 1 FROM order_transaction WHERE state_id = :id
                 UNION SELECT 1 FROM state_machine_history WHERE from_state_id = :id OR to_state_id = :id
                 LIMIT 1',
                ['id' => $stateId]
            );

            if ($inUse) {
                continue;
            }

            $this->connection->executeStatement(
                'DELETE FROM state_machine_transition WHERE from_state_id = :id OR to_state_id = :id',
                ['id' => $stateId]
            );
            $this->connection->executeStatement(
                'DELETE FROM state_machine_state_translation WHERE state_machine_state_id = :id',
                ['id' => $stateId]
            );
            $this->connection->executeStatement('DELETE FROM state_machine_state WHERE id = :id', ['id' => $stateId]);
        }
    }

    /**
     * @return void
     */
    public function activate(): void
    {
    }

    /**
     * @return void
     */
    public function deactivate(): void
    {
    }
}
