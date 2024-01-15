<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Util;

use Doctrine\DBAL\Connection;

/**
 * Migration Helper Class
 */
class MigrationHelper
{
    public static function getExecuteStatementMethod(): string
    {
        return (new \ReflectionClass(Connection::class))
            ->hasMethod('executeStatement') ? 'executeStatement' : 'executeQuery';
    }
}
