<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Pos\Run;

use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Shopware\Core\Framework\Log\Package;
use Swag\PayPal\Pos\Run\LogHandler;

#[Package('checkout')]
class LoggerFactory
{
    public function createLogger(): Logger
    {
        $logger = new Logger('mondu_mondupayment_pos');
        $logger->pushHandler(new LogHandler());
        $logger->pushProcessor(new PsrLogMessageProcessor());

        return $logger;
    }
}
