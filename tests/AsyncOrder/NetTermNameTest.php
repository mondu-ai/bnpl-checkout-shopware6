<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\AsyncOrder;

use Mondu\MonduPayment\Util\NetTermName;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;

class NetTermNameTest extends TestCase
{
    public function testTheTermInBracketsFollowsTheOffer(): void
    {
        static::assertSame('Rechnungskauf (60 Tage)', NetTermName::format('Rechnungskauf (30 Tage)', [60]));
        static::assertSame('Rechnungskauf (30/60 Tage)', NetTermName::format('Rechnungskauf (30 Tage)', [60, 30]));
        static::assertSame('SEPA direct debit (14 days)', NetTermName::format('SEPA direct debit (30 days)', [14]));
        static::assertSame('Facture (45 jours)', NetTermName::format('Facture (30 jours)', [45]));
        // Already adjusted once in this request.
        static::assertSame('Rechnungskauf (60 Tage)', NetTermName::format('Rechnungskauf (30/60 Tage)', [60]));
    }

    public function testOtherNamesStayAsTheyAre(): void
    {
        static::assertSame('Business net 30', NetTermName::format('Business net 30', [60]));
        static::assertSame('Echtzeitüberweisung', NetTermName::format('Echtzeitüberweisung', [3]));
        static::assertSame('Kauf auf Rechnung', NetTermName::format('Kauf auf Rechnung', [60]));
        static::assertSame('Rechnungskauf (30 Tage)', NetTermName::format('Rechnungskauf (30 Tage)', []));
    }

    public function testTheEntityIsRenamedForTheRequestOnly(): void
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setName('Rechnungskauf (30 Tage)');
        $paymentMethod->setTranslated(['name' => 'Rechnungskauf (30 Tage)']);

        NetTermName::apply($paymentMethod, [30, 60]);

        static::assertSame('Rechnungskauf (30/60 Tage)', $paymentMethod->getTranslation('name'));
        static::assertSame('Rechnungskauf (30/60 Tage)', $paymentMethod->getName());
    }
}
