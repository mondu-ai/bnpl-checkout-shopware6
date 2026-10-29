<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\AsyncOrder;

use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderFields;
use PHPUnit\Framework\TestCase;

/**
 * The field rules of POST /orders/create_async, as the Magento module has them.
 */
class AsyncOrderFieldsTest extends TestCase
{
    public function testEachMethodRequiresWhatTheApiDemands(): void
    {
        static::assertSame(['net_term'], AsyncOrderFields::requiredForOrder('invoice', 'DE', null));
        static::assertSame(['net_term', 'iban', 'account_holder'], AsyncOrderFields::requiredForOrder('direct_debit', 'DE', null));
        static::assertSame(['number_of_installments', 'iban', 'account_holder'], AsyncOrderFields::requiredForOrder('installment', 'DE', null));
        static::assertSame(['number_of_installments'], AsyncOrderFields::requiredForOrder('installment_by_invoice', 'DE', null));
    }

    public function testRegistrationIdIsRequiredOutsideGermanyOnly(): void
    {
        static::assertNotContains('registration_id', AsyncOrderFields::requiredForOrder('invoice', 'DE', null));
        static::assertContains('registration_id', AsyncOrderFields::requiredForOrder('invoice', 'BE', null));
        static::assertContains('registration_id', AsyncOrderFields::requiredForOrder('installment_by_invoice', 'nl', null));
        // An unknown country must not block an order the API would accept.
        static::assertNotContains('registration_id', AsyncOrderFields::requiredForOrder('invoice', null, null));
    }

    public function testOnlyAWellFormedIbanPasses(): void
    {
        static::assertTrue(AsyncOrderFields::isValidIban('DE02120300000000202051'));
        static::assertTrue(AsyncOrderFields::isValidIban('NL91ABNA0417164300'));
        static::assertTrue(AsyncOrderFields::isValidIban('GB29NWBK60161331926819'));
        // What the sanitizer makes of a spaced, lower case entry.
        static::assertTrue(AsyncOrderFields::isValidIban(AsyncOrderFields::sanitize(['iban' => 'de02 1203 0000 0000 2020 51'])['iban']));

        // Digits only, as typed into the admin form.
        static::assertFalse(AsyncOrderFields::isValidIban('123123123123'));
        // One digit off: the checksum fails.
        static::assertFalse(AsyncOrderFields::isValidIban('DE02120300000000202052'));
        // Too short.
        static::assertFalse(AsyncOrderFields::isValidIban('DE0212030000'));
    }

    public function testOwnerDetailsAreRequiredForSoleTradersOnly(): void
    {
        $soleTrader = AsyncOrderFields::requiredForOrder('invoice', 'DE', 'einzelunternehmen');
        static::assertContains('owner_first_name', $soleTrader);
        static::assertContains('owner_last_name', $soleTrader);
        static::assertContains('owner_birth_date', $soleTrader);

        static::assertNotContains('owner_birth_date', AsyncOrderFields::requiredForOrder('invoice', 'DE', 'kapital_und_personen_gesellschaft'));
    }

    public function testSanitizeKeepsKnownFieldsAndNormalisesTheIban(): void
    {
        $fields = AsyncOrderFields::sanitize([
            'net_term' => ' 30 ',
            'iban' => 'de02 1203-0000 0000 2020 51',
            'registration_id' => '',
            'unknown' => 'dropped',
            'account_holder' => null,
            'legal_form_category' => ['not', 'a', 'string'],
        ]);

        static::assertSame(['net_term' => '30', 'iban' => 'DE02120300000000202051'], $fields);
    }

    public function testPayNowIsNotOfferedForAdminOrders(): void
    {
        static::assertSame(['pay_now'], AsyncOrderFields::ADMIN_EXCLUDED_METHODS);
    }

    public function testTheCompanyComesFromTheCustomerOrTheBillingAddress(): void
    {
        static::assertSame('Mondu GmbH', AsyncOrderFields::companyName('Mondu GmbH', 'Other GmbH'));
        static::assertSame('Other GmbH', AsyncOrderFields::companyName('', 'Other GmbH'));
        static::assertSame('Other GmbH', AsyncOrderFields::companyName('  ', ' Other GmbH '));
        // No company at all, e.g. a sole trader.
        static::assertNull(AsyncOrderFields::companyName(null, ''));
    }
}
