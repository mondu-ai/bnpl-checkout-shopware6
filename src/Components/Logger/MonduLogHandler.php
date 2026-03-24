<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\Logger;

use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\LogRecord;

class MonduLogHandler extends RotatingFileHandler
{
    public function __construct(
        private readonly ConfigService $configService,
        string $filename,
        int $maxFiles = 14,
    ) {
        parent::__construct($filename, $maxFiles, Level::Debug);
    }

    public function isHandling(LogRecord $record): bool
    {
        if ($record->level->value >= Level::Error->value) {
            return true;
        }

        return $this->configService->isExtendedLogsEnabled();
    }
}