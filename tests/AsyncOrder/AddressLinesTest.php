<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\AsyncOrder;

use Mondu\MonduPayment\Util\AddressLines;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;

class AddressLinesTest extends TestCase
{
    public function testAdditionalLinesAreIgnoredByDefault(): void
    {
        static::assertSame(['Main St 1', null], AddressLines::build($this->address(), 'ignore', 'ignore'));
    }

    public function testAdditionalLinesGoWhereTheConfigSays(): void
    {
        static::assertSame(['Main St 1 Floor 2', 'Back yard'], AddressLines::build($this->address(), 'addtoaddressline1', 'addtoaddressline2'));
        static::assertSame(['Main St 1 Back yard', 'Floor 2'], AddressLines::build($this->address(), 'addtoaddressline2', 'addtoaddressline1'));
    }

    private function address(): CustomerAddressEntity
    {
        $address = new CustomerAddressEntity();
        $address->setStreet('Main St 1');
        $address->setAdditionalAddressLine1('Floor 2');
        $address->setAdditionalAddressLine2('Back yard');

        return $address;
    }
}
