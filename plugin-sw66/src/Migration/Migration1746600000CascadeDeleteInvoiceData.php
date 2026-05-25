<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1746600000CascadeDeleteInvoiceData extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1746600000;
    }

    public function update(Connection $connection): void
    {
        // Check if the FK exists before attempting to modify it
        $fkExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mondu_invoice_data'
               AND CONSTRAINT_NAME = 'mondu_invoice_data_ibfk_1'
               AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );

        if ((int) $fkExists === 1) {
            $connection->executeStatement('ALTER TABLE `mondu_invoice_data` DROP FOREIGN KEY `mondu_invoice_data_ibfk_1`');
        }

        // Re-add with ON DELETE CASCADE so deleting a document cascades to mondu_invoice_data
        $connection->executeStatement(
            'ALTER TABLE `mondu_invoice_data`
             ADD CONSTRAINT `mondu_invoice_data_ibfk_1`
             FOREIGN KEY (`document_id`) REFERENCES `document` (`id`) ON DELETE CASCADE'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
