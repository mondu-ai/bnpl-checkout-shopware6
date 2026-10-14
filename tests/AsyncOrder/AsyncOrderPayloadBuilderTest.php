<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\AsyncOrder;

use Mondu\MonduPayment\Components\AsyncOrder\Service\AsyncOrderPayloadBuilder;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Services\OrderServices\AbstractOrderLinesService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Context\LanguageInfo;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class AsyncOrderPayloadBuilderTest extends TestCase
{
    private ?OrderEntity $linesOrder = null;

    public function testTheOrderGoesOutUnderItsOwnReferenceWithTheConvertedLineItems(): void
    {
        $payload = $this->builder()->build(
            $this->convertedCart(),
            new Cart('token'),
            $this->salesChannelContext(),
            'invoice',
            'M_SW6_reference',
            ['net_term' => '30', 'registration_id' => 'HRB 1', 'iban' => 'DE02120300000000202051']
        );

        static::assertSame('M_SW6_reference', $payload['external_reference_id']);
        static::assertSame('invoice', $payload['payment_method']);
        static::assertSame(11900, $payload['gross_amount_cents']);
        static::assertSame(30, $payload['net_term']);
        static::assertSame('HRB 1', $payload['buyer']['registration_id']);
        // IBAN is not a field of the invoice method and is not sent with it.
        static::assertArrayNotHasKey('iban', $payload['buyer']);
        static::assertArrayNotHasKey('owners', $payload);
        static::assertSame('DE', $payload['billing_address']['country_code']);
        static::assertSame('de', $payload['language']);
        static::assertSame('DE123456789', $payload['buyer']['vat_number']);

        // The lines are computed from the line items the order is written with, ids included,
        // so their references match what the invoice sends later.
        static::assertNotNull($this->linesOrder);
        static::assertSame('10042', $this->linesOrder->getOrderNumber());
        static::assertSame(['line-item-id'], array_values($this->linesOrder->getLineItems()->getIds()));
        static::assertSame('line-item-id', $this->linesOrder->getLineItems()->first()->getUniqueIdentifier());
        static::assertSame(CartPrice::TAX_STATE_GROSS, $this->linesOrder->getTaxStatus());
    }

    public function testSoleTradersSendTheirOwner(): void
    {
        $payload = $this->builder()->build(
            $this->convertedCart(),
            new Cart('token'),
            $this->salesChannelContext(),
            'installment_by_invoice',
            'M_SW6_reference',
            [
                'number_of_installments' => '6',
                'legal_form_category' => 'einzelunternehmen',
                'owner_first_name' => 'Erika',
                'owner_last_name' => 'Muster',
                'owner_birth_date' => '1980-02-03',
            ]
        );

        static::assertSame(6, $payload['number_of_installments']);
        static::assertArrayNotHasKey('net_term', $payload);
        static::assertSame('einzelunternehmen', $payload['buyer']['legal_form_category']);
        static::assertSame([['first_name' => 'Erika', 'last_name' => 'Muster', 'birth_date' => '1980-02-03']], $payload['owners']);
    }

    private function builder(): AsyncOrderPayloadBuilder
    {
        $lines = $this->createMock(AbstractOrderLinesService::class);
        $lines->method('getLines')->willReturnCallback(function (OrderEntity $order): array {
            $this->linesOrder = $order;

            return [['line_items' => []]];
        });

        $config = $this->createMock(ConfigService::class);
        $config->method('setSalesChannelId')->willReturnSelf();
        $config->method('getHandlingAddressAdditionalField1')->willReturn('ignore');
        $config->method('getHandlingAddressAdditionalField2')->willReturn('ignore');

        return new AsyncOrderPayloadBuilder($lines, $config);
    }

    private function convertedCart(): array
    {
        $price = new CalculatedPrice(100.0, 100.0, new CalculatedTaxCollection(), new TaxRuleCollection());

        return [
            'id' => 'order-id',
            'orderNumber' => '10042',
            'price' => new CartPrice(100.0, 119.0, 100.0, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS),
            'shippingCosts' => new CalculatedPrice(0.0, 0.0, new CalculatedTaxCollection(), new TaxRuleCollection()),
            'lineItems' => [[
                'id' => 'line-item-id',
                'identifier' => 'cart-line-item-id',
                'referencedId' => 'product-id',
                'quantity' => 1,
                'type' => 'product',
                'label' => 'Product',
                'price' => $price,
                'payload' => ['productNumber' => 'SW1'],
                'position' => 1,
            ]],
        ];
    }

    private function salesChannelContext(): SalesChannelContext
    {
        $country = new CountryEntity();
        $country->setId('country-id');
        $country->setIso('DE');

        $address = new CustomerAddressEntity();
        $address->setId('address-id');
        $address->setStreet('Charlottenstraße 68');
        $address->setZipcode('10117');
        $address->setCity('Berlin');
        $address->setCountry($country);
        $address->setPhoneNumber('+4930123456');

        $customer = new CustomerEntity();
        $customer->setId('customer-id');
        $customer->setEmail('buyer@example.com');
        $customer->setFirstName('Erika');
        $customer->setLastName('Muster');
        $customer->setCompany('Mondu GmbH');
        $customer->setGuest(false);
        $customer->setCustomerNumber('10001');
        $customer->setVatIds(['DE123456789']);
        $customer->setActiveBillingAddress($address);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getSalesChannelId')->willReturn('sales-channel-id');
        $context->method('getContext')->willReturn(Context::createDefaultContext());
        $context->method('getLanguageInfo')->willReturn(new LanguageInfo('Deutsch', 'de-DE'));

        return $context;
    }
}
