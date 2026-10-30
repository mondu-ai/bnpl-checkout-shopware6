<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Migration;

use Doctrine\DBAL\Connection;
use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderStates;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prepares the plugin for orders the merchant creates in the admin (POST /orders/create_async):
 * - mondu_order_data.order_flow tells those orders apart from hosted checkout orders,
 * - the order_transaction state machine gets the two states such an order waits in.
 */
class Migration1791676800AsyncOrderFlow extends MigrationStep
{
    private const STATE_MACHINE = 'order_transaction.state';

    public function getCreationTimestamp(): int
    {
        return 1791676800;
    }

    public function update(Connection $connection): void
    {
        $this->addOrderFlowColumn($connection);

        $stateMachineId = $connection->fetchOne(
            'SELECT id FROM state_machine WHERE technical_name = :name',
            ['name' => self::STATE_MACHINE]
        );

        if (!$stateMachineId) {
            return;
        }

        foreach (AsyncOrderStates::STATES as $state) {
            $this->addState($connection, $stateMachineId, $state);
        }

        foreach (AsyncOrderStates::ENTRY_STATES as $state => $fromStates) {
            foreach ($fromStates as $fromState) {
                $this->addTransition($connection, $stateMachineId, $state, $fromState, $state);
            }
        }

        foreach (AsyncOrderStates::STATES as $state) {
            foreach (AsyncOrderStates::EXIT_TRANSITIONS as $action => $toState) {
                $this->addTransition($connection, $stateMachineId, $action, $state, $toState);
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private function addOrderFlowColumn(Connection $connection): void
    {
        $columnExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mondu_order_data' AND COLUMN_NAME = 'order_flow'"
        );

        if ((int) $columnExists === 0) {
            $connection->executeStatement("
                ALTER TABLE `mondu_order_data`
                ADD COLUMN `order_flow` VARCHAR(32) NOT NULL DEFAULT 'authorization' AFTER `order_state`
            ");
        }
    }

    private function addState(Connection $connection, string $stateMachineId, string $technicalName): void
    {
        $stateId = $this->getStateId($connection, $stateMachineId, $technicalName);

        if ($stateId === null) {
            $stateId = Uuid::randomBytes();
            $connection->insert('state_machine_state', [
                'id' => $stateId,
                'technical_name' => $technicalName,
                'state_machine_id' => $stateMachineId,
                'created_at' => (new \DateTime())->format('Y-m-d H:i:s.v'),
            ]);
        }

        // Every language needs a name, or the admin shows the state without a label.
        // Languages we have no wording for get the English one.
        $languages = $connection->fetchAllAssociative(
            'SELECT language.id, locale.code FROM language LEFT JOIN locale ON locale.id = language.locale_id'
        );
        $names = AsyncOrderStates::STATE_NAMES[$technicalName];

        foreach ($languages as $language) {
            $connection->executeStatement(
                'INSERT IGNORE INTO state_machine_state_translation (language_id, state_machine_state_id, name, created_at)
                 VALUES (:languageId, :stateId, :name, :createdAt)',
                [
                    'languageId' => $language['id'],
                    'stateId' => $stateId,
                    'name' => $names[$language['code']] ?? $names['en-GB'],
                    'createdAt' => (new \DateTime())->format('Y-m-d H:i:s.v'),
                ]
            );
        }
    }

    private function addTransition(
        Connection $connection,
        string $stateMachineId,
        string $actionName,
        string $fromState,
        string $toState
    ): void {
        $fromStateId = $this->getStateId($connection, $stateMachineId, $fromState);
        $toStateId = $this->getStateId($connection, $stateMachineId, $toState);

        if ($fromStateId === null || $toStateId === null) {
            return;
        }

        $exists = $connection->fetchOne(
            'SELECT 1 FROM state_machine_transition
             WHERE state_machine_id = :machine AND action_name = :action AND from_state_id = :from AND to_state_id = :to',
            ['machine' => $stateMachineId, 'action' => $actionName, 'from' => $fromStateId, 'to' => $toStateId]
        );

        if ($exists) {
            return;
        }

        $connection->insert('state_machine_transition', [
            'id' => Uuid::randomBytes(),
            'action_name' => $actionName,
            'state_machine_id' => $stateMachineId,
            'from_state_id' => $fromStateId,
            'to_state_id' => $toStateId,
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s.v'),
        ]);
    }

    private function getStateId(Connection $connection, string $stateMachineId, string $technicalName): ?string
    {
        $id = $connection->fetchOne(
            'SELECT id FROM state_machine_state WHERE state_machine_id = :machine AND technical_name = :name',
            ['machine' => $stateMachineId, 'name' => $technicalName]
        );

        return $id === false ? null : $id;
    }
}
