<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Util;

use Doctrine\DBAL\Connection;

class MigrationHelper
{
    public static function getExecuteStatementMethod(): string
    {
        return 'executeStatement';
    }
}
