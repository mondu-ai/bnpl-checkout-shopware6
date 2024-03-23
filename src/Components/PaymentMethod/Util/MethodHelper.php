<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\PaymentMethod\Util;

use Mondu\MonduPayment\Bootstrap\PaymentMethods;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduSepaHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduInstallmentHandler;
use Mondu\MonduPayment\Components\PaymentMethod\PaymentHandler\MonduInstallmentByInvoiceHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;

class MethodHelper
{
    const DEFAULT_MONDU_PAYMENT_METHOD = 'invoice';
    const MONDU_PAYMENT_METHODS = ['invoice', 'direct_debit', 'installment', 'installment_by_invoice'];

    public static function isMonduPayment(PaymentMethodEntity $paymentMethodEntity): bool
    {
        return array_key_exists($paymentMethodEntity->getHandlerIdentifier(), PaymentMethods::PAYMENT_METHODS);
    }

    public static function shortNameToMonduName($paymentMethodName)
    {
        $mapping = [
            'mondu_handler' => 'invoice',
            'mondu_sepa_handler' => 'direct_debit',
            'mondu_installment_handler' => 'installment',
            'mondu_installment_by_invoice_handler' => 'installment_by_invoice'
        ];

        return isset($mapping[$paymentMethodName]) ? $mapping[$paymentMethodName] : self::DEFAULT_MONDU_PAYMENT_METHOD;
    }

    public static function monduNameToHandler($paymentMethodName)
    {
        $mapping = [
            'invoice' => MonduHandler::class,
            'direct_debit' => MonduSepaHandler::class,
            'installment' => MonduInstallmentHandler::class,
            'installment_by_invoice' => MonduInstallmentByInvoiceHandler::class
        ];

        return isset($mapping[$paymentMethodName]) ? $mapping[$paymentMethodName] : '';
    }

    public static function monduPaymentMethodOrDefault($paymentMethod)
    {
        return in_array($paymentMethod, self::MONDU_PAYMENT_METHODS) ? $paymentMethod : self::DEFAULT_MONDU_PAYMENT_METHOD;
    }
}
