<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\StateMachine\Exception;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

class MonduException extends ShopwareHttpException
{
    private int $monduStatusCode;

    public function __construct($message, $statusCode = Response::HTTP_BAD_REQUEST)
    {
        $this->monduStatusCode = $statusCode;
        parent::__construct(
            $message
        );
    }

    public function getErrorCode(): string
    {
        return 'MONDU__ERROR';
    }

    public function getStatusCode(): int
    {
        return $this->monduStatusCode;
    }
}
