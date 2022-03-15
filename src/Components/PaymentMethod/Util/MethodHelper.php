<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PaymentMethod\Util;

use Mondu\MonduPayment\Bootstrap\PaymentMethods;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;

class MethodHelper
{
    public static function isMonduPayment(PaymentMethodEntity $paymentMethodEntity): bool
    {
        return array_key_exists($paymentMethodEntity->getHandlerIdentifier(), PaymentMethods::PAYMENT_METHODS);
    }
}
