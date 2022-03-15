<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\StateMachine\Exception;

use Shopware\Core\Framework\ShopwareHttpException;
use Symfony\Component\HttpFoundation\Response;

class MonduException extends ShopwareHttpException
{
    public function __construct($message)
    {
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
        return Response::HTTP_BAD_REQUEST;
    }
}
