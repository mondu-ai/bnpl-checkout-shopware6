<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1728850000MigrateSkipOrderStateValidation extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1728850000;
    }

    public function update(Connection $connection): void
    {
        // Migrate old boolean values to new string format
        // Old: false/0 -> New: 'no_skipping'
        // Old: true/1  -> New: 'skipping_all'
        
        $sql = "
            UPDATE `system_config`
            SET `configuration_value` = JSON_SET(
                `configuration_value`,
                '$._value',
                CASE
                    WHEN JSON_EXTRACT(`configuration_value`, '$._value') IN (1, true) 
                        THEN 'skipping_all'
                    WHEN JSON_EXTRACT(`configuration_value`, '$._value') IN (0, false)
                        THEN 'no_skipping'
                    ELSE JSON_UNQUOTE(JSON_EXTRACT(`configuration_value`, '$._value'))
                END
            )
            WHERE `configuration_key` = 'Mond1SW6.config.skipOrderStateValidation'
            AND JSON_TYPE(JSON_EXTRACT(`configuration_value`, '$._value')) IN ('BOOLEAN', 'INTEGER')
        ";

        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        // No destructive changes needed
    }
}

