<?php

/**
 * (328) Οι αριθμοί ενός ελληνικού λογαριασμού, όπως τους επιστρέφει το AI.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Pricing;

use EnergyCRM\Domain\Pricing\BillReading;
use PHPUnit\Framework\TestCase;

final class BillReadingTest extends TestCase
{
    public function testGreekNumbersBecomeFloats(): void
    {
        self::assertSame(0.149, BillReading::number('0,149'));
        self::assertSame(1325.5, BillReading::number('1.325,50'));
        self::assertSame(1325.0, BillReading::number('1.325'));
        self::assertSame(0.149, BillReading::number('0.149'));
        self::assertSame(640.0, BillReading::number('640 kWh'));
        self::assertSame(5.0, BillReading::number(5));
        self::assertNull(BillReading::number('—'));
        self::assertNull(BillReading::number(null));
    }

    public function testAPriceInEuroPerMegawattHourIsConverted(): void
    {
        $bill = BillReading::from(['price_kwh' => 149, 'kwh' => 640, 'period_days' => 62]);

        self::assertSame(0.149, $bill['price_kwh']);
    }

    public function testTheDaysComeFromTheDatesWhenNotWritten(): void
    {
        $bill = BillReading::from(['period_from' => '2026-07-20', 'period_to' => '2026-09-20']);

        self::assertSame(62, $bill['period_days']);
    }

    public function testNonsenseBecomesNullNotANumber(): void
    {
        $bill = BillReading::from([
            'energy_type' => 'water', 'category' => 'Γ1', 'period_days' => 900,
            'kwh' => -5, 'price_kwh' => 'n/a', 'fixed_month' => 5000, 'provider_name' => 'null',
        ]);

        self::assertNull($bill['energy_type']);
        self::assertNull($bill['category']);
        self::assertNull($bill['period_days']);
        self::assertNull($bill['kwh']);
        self::assertNull($bill['price_kwh']);
        self::assertNull($bill['fixed_month']);
        self::assertNull($bill['provider_name']);
    }

    public function testAGoodReadingSurvivesIntact(): void
    {
        $bill = BillReading::from([
            'energy_type' => 'Power', 'provider_name' => ' ΔΕΗ ', 'category' => 'home',
            'period_days' => '62', 'kwh' => '640', 'price_kwh' => '0,1490', 'fixed_month' => '5,00',
        ]);

        self::assertSame([
            'energy_type'   => 'power',
            'provider_name' => 'ΔΕΗ',
            'category'      => 'home',
            'period_days'   => 62,
            'kwh'           => 640.0,
            'price_kwh'     => 0.149,
            'fixed_month'   => 5.0,
        ], $bill);
    }
}
