<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PaymentMethod\Util;

use Mondu\MonduPayment\Bootstrap\PaymentMethods;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduSepaHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduInstallmentHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;

/**
 * Method Helper Class
 */
class MethodHelper
{
    const DEFAULT_MONDU_PAYMENT_METHOD = 'invoice';
    const MONDU_PAYMENT_METHODS = ['invoice', 'direct_debit', 'installment'];

    public static function isMonduPayment(PaymentMethodEntity $paymentMethodEntity): bool
    {
        return array_key_exists($paymentMethodEntity->getHandlerIdentifier(), PaymentMethods::PAYMENT_METHODS);
    }

    public static function shortNameToMonduName($paymentMethodName)
    {
        $mapping = [
            'mondu_handler' => 'invoice',
            'mondu_sepa_handler' => 'direct_debit',
            'mondu_installment_handler' => 'installment'
        ];

        return $mapping[ $paymentMethodName ] ?? self::DEFAULT_MONDU_PAYMENT_METHOD;
    }

    public static function monduNameToHandler($paymentMethodName)
    {
        $mapping = [
            'invoice' => MonduHandler::class,
            'direct_debit' => MonduSepaHandler::class,
            'installment' => MonduInstallmentHandler::class
        ];

        return $mapping[$paymentMethodName] ?? '';
    }

    public static function monduPaymentMethodOrDefault($paymentMethod)
    {
        return in_array($paymentMethod, self::MONDU_PAYMENT_METHODS) ? $paymentMethod : self::DEFAULT_MONDU_PAYMENT_METHOD;
    }
}
