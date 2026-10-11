<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\AsyncOrder;

use Mondu\MonduPayment\Components\AsyncOrder\AsyncOrderErrorFormatter;
use PHPUnit\Framework\TestCase;

class AsyncOrderErrorFormatterTest extends TestCase
{
    public function testMissingFieldsAreNamedInPlainWords(): void
    {
        $message = AsyncOrderErrorFormatter::format(['errors' => [
            ['name' => 'net_term', 'details' => 'must be filled'],
            ['name' => 'owners.0.birth_date', 'details' => 'is missing'],
        ], 'status' => 422]);

        static::assertSame('Mondu: Net term (days) is required.; Owner date of birth is required.', $message);
    }

    public function testKnownRefusalsGetAnActionableMessage(): void
    {
        static::assertStringContainsString(
            'below the minimum',
            AsyncOrderErrorFormatter::format(['errors' => [['name' => '', 'details' => 'order amount is below the minimum amount']]])
        );
        static::assertStringContainsString(
            'net term is not available',
            AsyncOrderErrorFormatter::format(['errors' => [['name' => 'net_term', 'details' => 'net term not available']]])
        );
    }

    public function testUnknownErrorsArePassedOnAndAnEmptyAnswerStillSaysSomething(): void
    {
        static::assertSame('Mondu: buyer.foo: odd', AsyncOrderErrorFormatter::format(['errors' => [['name' => 'buyer.foo', 'details' => 'odd']]]));
        static::assertSame('Mondu: error creating the order.', AsyncOrderErrorFormatter::format(null));
    }
}
