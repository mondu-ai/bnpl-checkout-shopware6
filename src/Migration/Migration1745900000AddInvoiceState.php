<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1745900000AddInvoiceState extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1745900000;
    }

    public function update(Connection $connection): void
    {
        $columnExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME = 'mondu_invoice_data' AND COLUMN_NAME = 'invoice_state'"
        );

        if ((int) $columnExists === 0) {
            $connection->executeStatement("
                ALTER TABLE `mondu_invoice_data`
                ADD COLUMN `invoice_state` VARCHAR(50) NULL DEFAULT NULL AFTER `external_invoice_uuid`
            ");
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
