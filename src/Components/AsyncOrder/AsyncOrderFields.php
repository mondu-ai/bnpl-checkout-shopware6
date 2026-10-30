<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Components\AsyncOrder;

/**
 * Fields the merchant fills in the admin when creating an order paid with Mondu.
 *
 * POST /orders/create_async has no hosted checkout where the buyer could enter these, so
 * the merchant supplies them. Which ones are needed depends on the payment method, and
 * the rules below are taken from the 422 answers of that endpoint, the same as in the
 * Magento module.
 *
 * Methods are keyed by their Mondu name (see MethodHelper::shortNameToMonduName()).
 */
final class AsyncOrderFields
{
    public const FIELD_REGISTRATION_ID = 'registration_id';
    public const FIELD_LEGAL_FORM_CATEGORY = 'legal_form_category';
    public const FIELD_NET_TERM = 'net_term';
    public const FIELD_NUMBER_OF_INSTALLMENTS = 'number_of_installments';
    public const FIELD_IBAN = 'iban';
    public const FIELD_ACCOUNT_HOLDER = 'account_holder';
    public const FIELD_OWNER_FIRST_NAME = 'owner_first_name';
    public const FIELD_OWNER_LAST_NAME = 'owner_last_name';
    public const FIELD_OWNER_BIRTH_DATE = 'owner_birth_date';

    /**
     * legal_form_category value that makes the `owners` object mandatory.
     */
    public const LEGAL_FORM_CATEGORY_SOLE_TRADER = 'einzelunternehmen';

    /**
     * Values the API accepts for legal_form_category. The admin shows translated labels.
     */
    public const LEGAL_FORM_CATEGORIES = [
        'einzelunternehmen',
        'kapital_und_personen_gesellschaft',
        'offentliche_auftraggeber_einrichtung',
        'andere_gesellschaftsform',
    ];

    public const INSTALLMENT_OPTIONS = [3, 6, 12];

    /**
     * Countries where Mondu accepts a buyer without a registration_id.
     *
     * Everywhere else the field is mandatory, no matter which payment method is
     * selected: the rule is about the buyer's register, not about the method.
     */
    public const REGISTRATION_ID_OPTIONAL_COUNTRIES = ['DE'];

    /**
     * Country-specific register the registration_id comes from.
     *
     * @see https://docs.mondu.ai/reference/registration-id-examples
     */
    public const REGISTRATION_ID_HINTS = [
        'DE' => 'HRB (Handelsregister), e.g. HRB 232626 B',
        'NL' => 'KVK (Kamer van Koophandel), e.g. 86653938',
        'BE' => 'KBO/BCE (Belgian Enterprise Register), e.g. 0465515965',
        'GB' => 'CRN (Companies House), e.g. 14681433',
        'LU' => 'RCS (Luxembourg Business Registers), e.g. B91982',
        'CH' => 'Commercial register (Handelsregister), e.g. CHE-245.518.760',
        'FR' => 'SIRET, e.g. 883846123',
        'GR' => 'ΑΦΜ / TIN (Greek tax identification number), e.g. 800863970',
    ];

    /**
     * Owner attributes required by the API when the buyer is a sole trader.
     */
    public const OWNER_FIELDS = [
        self::FIELD_OWNER_FIRST_NAME,
        self::FIELD_OWNER_LAST_NAME,
        self::FIELD_OWNER_BIRTH_DATE,
    ];

    /**
     * Mondu payment method => fields the API demands for it.
     */
    public const REQUIRED_BY_METHOD = [
        'invoice' => [
            self::FIELD_NET_TERM,
        ],
        'direct_debit' => [
            self::FIELD_NET_TERM,
            self::FIELD_IBAN,
            self::FIELD_ACCOUNT_HOLDER,
        ],
        'installment' => [
            self::FIELD_NUMBER_OF_INSTALLMENTS,
            self::FIELD_IBAN,
            self::FIELD_ACCOUNT_HOLDER,
        ],
        'installment_by_invoice' => [
            self::FIELD_NUMBER_OF_INSTALLMENTS,
        ],
        'pay_now' => [],
    ];

    /**
     * Mondu payment method => fields that are shown but not always enforced.
     *
     * The API accepts these for every method. legal_form_category is optional
     * everywhere; registration_id is listed here because its requirement depends on
     * the billing country rather than on the method, see isRegistrationIdRequired().
     */
    public const OPTIONAL_BY_METHOD = [
        'invoice' => [self::FIELD_REGISTRATION_ID, self::FIELD_LEGAL_FORM_CATEGORY],
        'direct_debit' => [self::FIELD_REGISTRATION_ID, self::FIELD_LEGAL_FORM_CATEGORY],
        'installment' => [self::FIELD_REGISTRATION_ID, self::FIELD_LEGAL_FORM_CATEGORY],
        'installment_by_invoice' => [self::FIELD_REGISTRATION_ID, self::FIELD_LEGAL_FORM_CATEGORY],
        'pay_now' => [self::FIELD_REGISTRATION_ID, self::FIELD_LEGAL_FORM_CATEGORY],
    ];

    /**
     * Every field the admin form may send.
     */
    public const ALL_FIELDS = [
        self::FIELD_REGISTRATION_ID,
        self::FIELD_LEGAL_FORM_CATEGORY,
        self::FIELD_NET_TERM,
        self::FIELD_NUMBER_OF_INSTALLMENTS,
        self::FIELD_IBAN,
        self::FIELD_ACCOUNT_HOLDER,
        self::FIELD_OWNER_FIRST_NAME,
        self::FIELD_OWNER_LAST_NAME,
        self::FIELD_OWNER_BIRTH_DATE,
    ];

    /**
     * Mondu payment methods an order can carry a net term for.
     *
     * Kept apart from REQUIRED_BY_METHOD: that lists what the async API demands, and
     * pay now does not demand a term but does accept one (3 days on the test account).
     * Both installment methods refuse any term with a 422.
     */
    public const NET_TERM_METHODS = ['invoice', 'direct_debit', 'pay_now'];

    /**
     * Payment methods the admin may not create an order with.
     *
     * Pay now is left out of admin orders, as in the Magento module.
     */
    public const ADMIN_EXCLUDED_METHODS = ['pay_now'];

    private function __construct()
    {
    }

    /**
     * @return string[]
     */
    public static function requiredFor(string $method): array
    {
        return self::REQUIRED_BY_METHOD[$method] ?? [];
    }

    /**
     * @return string[]
     */
    public static function optionalFor(string $method): array
    {
        return self::OPTIONAL_BY_METHOD[$method] ?? [];
    }

    /**
     * Every field the admin form should display for the given method.
     *
     * @return string[]
     */
    public static function visibleFor(string $method): array
    {
        return array_merge(self::requiredFor($method), self::optionalFor($method));
    }

    /**
     * Whether registration_id has to be filled for a buyer in the given country.
     *
     * An unknown country is treated as "not required": guessing wrong here would block
     * an order the API would have accepted.
     */
    public static function isRegistrationIdRequired(?string $countryCode): bool
    {
        if ($countryCode === null || $countryCode === '') {
            return false;
        }

        return !in_array(strtoupper($countryCode), self::REGISTRATION_ID_OPTIONAL_COUNTRIES, true);
    }

    public static function registrationIdHint(?string $countryCode): ?string
    {
        if ($countryCode === null || $countryCode === '') {
            return null;
        }

        return self::REGISTRATION_ID_HINTS[strtoupper($countryCode)] ?? null;
    }

    /**
     * Fields that become mandatory because of the selected legal_form_category.
     *
     * @return string[]
     */
    public static function requiredForCategory(?string $category): array
    {
        return $category === self::LEGAL_FORM_CATEGORY_SOLE_TRADER ? self::OWNER_FIELDS : [];
    }

    /**
     * Fields that must be filled for this method, buyer country and legal form.
     *
     * @return string[]
     */
    public static function requiredForOrder(string $method, ?string $countryCode, ?string $category): array
    {
        $required = array_merge(self::requiredFor($method), self::requiredForCategory($category));

        if (self::isRegistrationIdRequired($countryCode)) {
            $required[] = self::FIELD_REGISTRATION_ID;
        }

        return array_values(array_unique($required));
    }

    /**
     * The buyer's company: the customer's, the billing address's as fallback, the same
     * source the hosted checkout uses. A sole trader may have none.
     */
    public static function companyName(?string $customerCompany, ?string $addressCompany): ?string
    {
        foreach ([$customerCompany, $addressCompany] as $company) {
            $company = trim((string) $company);
            if ($company !== '') {
                return $company;
            }
        }

        return null;
    }

    /**
     * Whether the value is a well formed IBAN: country code, check digits, account part
     * of the right length, and the mod-97 checksum. Expects the sanitized form, upper
     * case without spaces (see sanitize()).
     *
     * Mondu accepts an order with a malformed IBAN and then never decides on it, so the
     * order would wait without an answer instead of being refused with a reason.
     */
    public static function isValidIban(string $iban): bool
    {
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        // Country code and check digits go to the end, letters become 10..35.
        $digits = '';
        foreach (str_split(substr($iban, 4) . substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $remainder = (int) ($remainder . $chunk) % 97;
        }

        return $remainder === 1;
    }

    /**
     * Keeps the known fields only, trimmed, with empty values dropped.
     *
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public static function sanitize(array $input): array
    {
        $fields = [];

        foreach (self::ALL_FIELDS as $field) {
            if (!array_key_exists($field, $input) || $input[$field] === null || is_array($input[$field])) {
                continue;
            }

            $value = trim((string) $input[$field]);
            if ($field === self::FIELD_IBAN) {
                $value = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
            }

            if ($value !== '') {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }
}
