<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Mondu\MonduPayment\Util\MigrationHelper;

class Migration1645455321OrderData extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1645455321;
    }

    public function update(Connection $connection): void
    {
        $methodName = MigrationHelper::getExecuteStatementMethod();
        $connection->{$methodName}('
            CREATE TABLE `mondu_order_data` (
              `id` binary(16) NOT NULL,
              `version_id` binary(16) NOT NULL,
              `order_id` binary(16) NOT NULL,
              `order_version_id` binary(16) NOT NULL,
              `reference_id` varchar(255) NOT NULL,
              `order_state` VARCHAR(20) NOT NULL,
              `viban` varchar(255) NULL,
              `duration` int NOT NULL,
              `external_invoice_number` VARCHAR(255) NULL,
              `external_invoice_url` TEXT NULL,
              `external_delivery_note_url` TEXT NULL,
              `successful` tinyint(1) NOT NULL,
              `updated_at` DATETIME NULL,
              PRIMARY KEY (`id`, `version_id`),
              FOREIGN KEY (`order_id`,`order_version_id`) REFERENCES `order` (`id`, `version_id`) ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ');
        // implement update
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
