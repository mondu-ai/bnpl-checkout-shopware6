<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1652857810CreateMonduOrderInvoicesTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1652857810;
    }

    public function update(Connection $connection): void
    {
        $query = <<<SQL
          CREATE TABLE IF NOT EXISTS `mondu_invoice_data` (
          `id` binary(16) NOT NULL,
          `version_id` binary(16) NOT NULL,
          `order_id` binary(16) NOT NULL,
          `order_version_id` binary(16) NOT NULL,
          `document_id` binary(16) NOT NULL,
          `invoice_number` varchar(255) NOT NULL,
          `external_invoice_uuid` varchar(255) NOT NULL,
          `created_at` DATETIME(3) NOT NULL,
          `updated_at` DATETIME(3),
          PRIMARY KEY (`id`, `version_id`),
          FOREIGN KEY (`document_id`) REFERENCES `document` (`id`),
          FOREIGN KEY (`order_id`,`order_version_id`) REFERENCES `order` (`id`, `version_id`) ON UPDATE CASCADE ON DELETE CASCADE
        ) ENGINE='InnoDB' DEFAULT CHARSET=utf8mb4;
      SQL;

        $connection->executeStatement($query);
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
