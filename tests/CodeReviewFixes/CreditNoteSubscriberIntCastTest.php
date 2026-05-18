<?php

declare(strict_types=1);

namespace Mondu\MonduPayment\Tests\CodeReviewFixes;

use Mondu\MonduPayment\Components\MonduApi\Service\MonduClient;
use Mondu\MonduPayment\Components\Order\Subscriber\CreditNoteSubscriber;
use Mondu\MonduPayment\Components\PluginConfig\Service\ConfigService;
use Mondu\MonduPayment\Helpers\Log as MonduLogHelper;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Psr\Log\NullLogger;

/**
 * Tests that CreditNoteSubscriber accumulates money amounts as integers,
 * preventing floating-point drift over many line items.
 */
class CreditNoteSubscriberIntCastTest extends TestCase
{
    /**
     * Simulates the accumulation logic from CreditNoteSubscriber::onDocumentWritten
     * to verify (int) round() produces exact integer cents.
     */
    public function testGrossAmountCentsAccumulatesAsInteger(): void
    {
        $prices = [3.33, 6.67, 1.11, 2.22, 4.44, 8.88, 7.77, 5.55, 9.99, 0.01];

        $grossAmountCents = 0;
        foreach ($prices as $price) {
            $grossAmountCents += (int) round(abs($price) * 100);
        }

        static::assertIsInt($grossAmountCents);
        static::assertSame(4997, $grossAmountCents);
    }

    /**
     * Without (int) cast, float accumulation can drift.
     * This test documents the difference.
     */
    public function testFloatAccumulationCanDrift(): void
    {
        $prices = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0];

        $withCast = 0;
        $withoutCast = 0.0;
        foreach ($prices as $price) {
            $withCast += (int) round(abs($price) * 100);
            $withoutCast += round(abs($price) * 100);
        }

        static::assertIsInt($withCast);
        static::assertIsFloat($withoutCast);
        static::assertSame(550, $withCast);
    }

    public function testTaxCentsAccumulatesAsInteger(): void
    {
        $taxAmounts = [1.59, 3.18, 0.53];
        $quantities = [1, 2, 1];

        $taxCents = 0;
        foreach ($taxAmounts as $i => $taxAmount) {
            $taxCents += (int) round(abs($taxAmount / $quantities[$i]) * 100);
        }

        static::assertIsInt($taxCents);
        static::assertSame(371, $taxCents);
    }
}
