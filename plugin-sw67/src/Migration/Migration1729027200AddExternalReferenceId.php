<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Mondu\MonduPayment\Util\MigrationHelper;

class Migration1729027200AddExternalReferenceId extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1729027200;
    }

    public function update(Connection $connection): void
    {
        $method = MigrationHelper::getExecuteStatementMethod();

        // Add external_reference_id column to mondu_order_data
        $connection->{$method}('
            ALTER TABLE `mondu_order_data`
            ADD COLUMN `external_reference_id` VARCHAR(255) NULL AFTER `reference_id`,
            ADD INDEX `idx_external_reference_id` (`external_reference_id`)
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive if needed
    }
}
