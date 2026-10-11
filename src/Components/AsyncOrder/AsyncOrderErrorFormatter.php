<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\AsyncOrder;

/**
 * Turns a Mondu 422 answer of POST /orders/create_async into one line the merchant can act on.
 *
 * Example payload: {"errors":[{"details":"must be filled","name":"net_term"}],"status":422}
 * becomes "Net term (days) is required."
 */
final class AsyncOrderErrorFormatter
{
    private const FIELD_LABELS = [
        'net_term' => 'Net term (days)',
        'number_of_installments' => 'Number of installments',
        'iban' => 'IBAN',
        'buyer.iban' => 'IBAN',
        'account_holder' => 'Account holder',
        'buyer.account_holder' => 'Account holder',
        'company_name' => 'Company name',
        'buyer.company_name' => 'Company name',
        'email' => 'Email',
        'buyer.email' => 'Email',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'phone' => 'Phone',
        'buyer.registration_id' => 'Registration ID',
        'buyer.legal_form_category' => 'Legal form category',
        'owners' => 'Owner details',
        'owners.0.first_name' => 'Owner first name',
        'owners.0.last_name' => 'Owner last name',
        'owners.0.birth_date' => 'Owner date of birth',
    ];

    private function __construct()
    {
    }

    public static function format(?array $response): string
    {
        $errors = $response['errors'] ?? null;
        if (!is_array($errors) || $errors === []) {
            return 'Mondu: error creating the order.';
        }

        $parts = [];
        foreach ($errors as $error) {
            if (!is_array($error)) {
                $parts[] = (string) $error;
                continue;
            }

            $name = (string) ($error['name'] ?? '');
            $details = (string) ($error['details'] ?? '');
            $friendly = self::friendlyMessage($name, $details);

            if ($friendly !== null) {
                $parts[] = $friendly;
            } elseif ($name !== '' && $details !== '') {
                $parts[] = sprintf('%s: %s', $name, $details);
            } elseif ($details !== '' || $name !== '') {
                $parts[] = $details !== '' ? $details : $name;
            }
        }

        return 'Mondu: ' . ($parts === [] ? 'error creating the order.' : implode('; ', array_unique($parts)));
    }

    private static function friendlyMessage(string $name, string $details): ?string
    {
        if (str_contains($details, 'minimum amount')) {
            return 'The order total is below the minimum for this payment method. Please add more products or choose a different payment method.';
        }
        if (str_contains($details, 'maximum amount')) {
            return 'The order total exceeds the maximum for this payment method. Please reduce the order total or choose a different payment method.';
        }
        if ($name === 'payment_method' && str_contains($details, 'not supported')) {
            return 'This payment method is not available for your merchant account.';
        }
        if ($name === 'net_term' && str_contains($details, 'not available')) {
            return 'The selected net term is not available for this buyer. Please choose a different one.';
        }
        if ($name === 'currency' && str_contains($details, 'not supported')) {
            return 'The order currency is not supported by Mondu.';
        }

        $label = self::FIELD_LABELS[$name] ?? $name;

        if (str_contains($details, 'must be filled') || str_contains($details, 'is missing')) {
            return sprintf('%s is required.', $label);
        }
        if (str_contains($details, 'invalid format') || str_contains($details, 'is invalid')) {
            return sprintf('%s has an invalid format. Please check and correct the value.', $label);
        }

        return null;
    }
}
