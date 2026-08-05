<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Quote;

use EnergyCRM\Domain\Quote\SavingsEstimate;
use PHPUnit\Framework\TestCase;

final class SavingsEstimateTest extends TestCase
{
    public function testTheStandingChargeIsChargedTwelveTimesNotOnce(): void
    {
        // No consumption at all, so the whole bill is the standing charge.
        $estimate = SavingsEstimate::compare(0.0, 0.0, 10.0, 0.0, 0.0);

        self::assertSame(120.0, $estimate->currentAnnual);
        self::assertSame(120.0, $estimate->savings);
    }

    public function testAKnownBillComesOutRight(): void
    {
        // 3.500 kWh at 0,12 €/kWh plus 5 €/month = 420 + 60 = 480.
        // The offer: 0,10 €/kWh plus 4 €/month = 350 + 48 = 398.
        $estimate = SavingsEstimate::compare(3500.0, 0.12, 5.0, 0.10, 4.0);

        self::assertEqualsWithDelta(480.0, $estimate->currentAnnual, 0.001);
        self::assertEqualsWithDelta(398.0, $estimate->offeredAnnual, 0.001);
        self::assertEqualsWithDelta(82.0, $estimate->savings, 0.001);
        self::assertEqualsWithDelta(17.083, $estimate->percentage, 0.001);
    }

    /** A worse offer must read as a loss, not be quietly floored at zero. */
    public function testAnOfferThatCostsMoreProducesNegativeSavings(): void
    {
        $estimate = SavingsEstimate::compare(1000.0, 0.10, 0.0, 0.15, 0.0);

        self::assertEqualsWithDelta(-50.0, $estimate->savings, 0.001);
        self::assertTrue($estimate->isWorseOff());
    }

    public function testAnOfferThatSavesMoneyIsNotWorseOff(): void
    {
        self::assertFalse(SavingsEstimate::compare(1000.0, 0.15, 0.0, 0.10, 0.0)->isWorseOff());
    }

    /** Nothing to compare against: a percentage of zero is zero, not infinity. */
    public function testNoCurrentBillGivesZeroPerCentRatherThanDividingByZero(): void
    {
        $estimate = SavingsEstimate::compare(0.0, 0.0, 0.0, 0.0, 0.0);

        self::assertSame(0.0, $estimate->percentage);
        self::assertSame(0.0, $estimate->savings);
    }

    /** A minus sign in the form must not manufacture savings. */
    public function testNegativeInputsAreTreatedAsZero(): void
    {
        $estimate = SavingsEstimate::compare(-1000.0, -0.5, -3.0, 0.10, 0.0);

        self::assertSame(0.0, $estimate->currentAnnual);
        self::assertSame(0.0, $estimate->offeredAnnual);
        self::assertSame(0.0, $estimate->savings);
    }
}
