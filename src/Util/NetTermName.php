<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Util;

use Shopware\Core\Checkout\Payment\PaymentMethodEntity;

/**
 * Keeps the term in a payment method name in line with the terms on offer.
 *
 * The methods are installed as "Rechnungskauf (30 Tage)" and "SEPA-Lastschrift (30 Tage)",
 * which is wrong once the merchant offers other terms. Only a name that ends in a term in
 * brackets changes, so a name the merchant wrote without one stays as it is. The unit word
 * is kept from the name, so every language keeps its own.
 */
final class NetTermName
{
    private const TERM_SUFFIX = '/\(\s*\d+(?:\s*[\/,]\s*\d+)*\s+([^\d()]+?)\s*\)\s*$/u';

    private function __construct()
    {
    }

    /**
     * "Rechnungskauf (30 Tage)" with [60] gives "Rechnungskauf (60 Tage)", with [30, 60]
     * "Rechnungskauf (30/60 Tage)". Without terms the name is returned unchanged.
     *
     * @param int[] $netTerms
     */
    public static function format(string $name, array $netTerms): string
    {
        if ($netTerms === [] || !preg_match(self::TERM_SUFFIX, $name, $match)) {
            return $name;
        }

        sort($netTerms);

        return (string) preg_replace(
            self::TERM_SUFFIX,
            '(' . implode('/', array_unique($netTerms)) . ' ' . $match[1] . ')',
            $name
        );
    }

    /**
     * Renames the entity for the current request only, the stored name is not touched.
     *
     * @param int[] $netTerms
     */
    public static function apply(?PaymentMethodEntity $paymentMethod, array $netTerms): void
    {
        if ($paymentMethod === null || $netTerms === []) {
            return;
        }

        $translated = (string) ($paymentMethod->getTranslation('name') ?? $paymentMethod->getName() ?? '');
        $paymentMethod->addTranslated('name', self::format($translated, $netTerms));

        if ($paymentMethod->getName() !== null) {
            $paymentMethod->setName(self::format($paymentMethod->getName(), $netTerms));
        }
    }
}
