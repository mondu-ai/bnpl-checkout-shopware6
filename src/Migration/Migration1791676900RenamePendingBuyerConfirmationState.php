<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Migration;

use Doctrine\DBAL\Connection;
use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderStates;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Removes "(Mondu)" from the name of the pending buyer confirmation state on shops that
 * already ran Migration1791676800AsyncOrderFlow: the buyer acts in this state, not Mondu.
 * Only names that still carry the suffix are changed, so a name the merchant set stays.
 */
class Migration1791676900RenamePendingBuyerConfirmationState extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1791676900;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(
            "UPDATE state_machine_state_translation translation
             INNER JOIN state_machine_state state ON state.id = translation.state_machine_state_id
             INNER JOIN state_machine ON state_machine.id = state.state_machine_id
             SET translation.name = TRIM(REPLACE(translation.name, '(Mondu)', '')),
                 translation.updated_at = NOW(3)
             WHERE state_machine.technical_name = 'order_transaction.state'
               AND state.technical_name = :state
               AND translation.name LIKE '%(Mondu)'",
            ['state' => AsyncOrderStates::STATE_PENDING_BUYER_CONFIRMATION]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
