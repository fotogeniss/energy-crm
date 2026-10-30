<?php

/**
 * (328) Ετήσιο κόστος προμήθειας ανά κανόνα τιμής.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Pricing;

use EnergyCRM\Domain\Pricing\ProgramPricing;
use PHPUnit\Framework\TestCase;

final class ProgramPricingTest extends TestCase
{
    public function testAFlatPriceIsEnergyPlusTwelveMonthsOfStandingCharge(): void
    {
        $program = ['price_kwh' => '0.14900', 'fixed_charge' => '5.00', 'pricing' => null];

        self::assertSame(621.73, ProgramPricing::annualCost($program, 3770));
        self::assertSame('flat', ProgramPricing::rule($program)['kind']);
    }

    public function testAProgramWithoutAPriceIsNotCompared(): void
    {
        self::assertNull(ProgramPricing::annualCost(['price_kwh' => null, 'fixed_charge' => '5'], 3000));
        self::assertNull(ProgramPricing::annualCost(['price_kwh' => '0', 'fixed_charge' => '5'], 3000));
        self::assertSame('none', ProgramPricing::rule([])['kind']);
    }

    public function testBalanceTiersCountThirtyDayPeriodsNotMonths(): void
    {
        // Value Balance 12M: 139 €/MWh έως 150 kWh / 30 ημέρες, 219 μετά, πάγιο 10,90 / 30 ημέρες.
        $program = [
            'price_kwh'    => null,
            'fixed_charge' => '10.90',
            'pricing'      => '{"kind":"tiered","tiers":[[150,0.139],[null,0.219]]}',
        ];

        $first = 150 * 365 / 30;
        $want  = $first * 0.139 + (3770 - $first) * 0.219 + 10.90 * 365 / 30;

        self::assertSame(round($want, 2), ProgramPricing::annualCost($program, 3770));

        // Μέσα στο πρώτο κλιμάκιο: όλα στην πρώτη τιμή.
        self::assertSame(round(1000 * 0.139 + 10.90 * 365 / 30, 2), ProgramPricing::annualCost($program, 1000));
    }

    public function testAPackageChargesItsSupplyPartAndOnlyTheExcessAboveIt(): void
    {
        $program = [
            'pricing' => ['kind' => 'package', 'package_kwh' => 1325, 'supply_month' => 21.50, 'excess_kwh' => 0.099],
        ];

        self::assertSame(258.0, ProgramPricing::annualCost($program, 900));
        self::assertSame(258.0, ProgramPricing::annualCost($program, 1325));
        self::assertSame(round(258 + 675 * 0.099, 2), ProgramPricing::annualCost($program, 2000));
    }

    public function testABrokenRuleFallsBackToTheFlatColumns(): void
    {
        $program = ['price_kwh' => '0.2', 'fixed_charge' => null, 'pricing' => '{"kind":"package"}'];

        self::assertSame(200.0, ProgramPricing::annualCost($program, 1000));
    }

    public function testTheNoteTravelsWithTheRule(): void
    {
        $rule = ProgramPricing::rule([
            'price_kwh' => '0.177', 'fixed_charge' => '11.9', 'pricing' => '{"kind":"flat","note":"με έκπτωση"}',
        ]);

        self::assertSame('με έκπτωση', $rule['note']);
    }

    public function testAnnualiseScalesTheBillPeriodToAYear(): void
    {
        self::assertSame(3768.0, ProgramPricing::annualise(640, 62));
        self::assertSame(0.0, ProgramPricing::annualise(640, 0));
    }

    public function testOnlyVariablePricesGoStaleAndOnlyAfterFortyFiveDays(): void
    {
        $variable = ['price_type' => 'variable', 'price_updated' => '2026-09-01'];

        self::assertFalse(ProgramPricing::isStale($variable, '2026-10-16'));
        self::assertTrue(ProgramPricing::isStale($variable, '2026-10-17'));
        self::assertTrue(ProgramPricing::isStale(['price_type' => 'variable', 'price_updated' => null], '2026-10-01'));
        $fixed = ['price_type' => 'fixed', 'price_updated' => '2025-01-01'];
        self::assertFalse(ProgramPricing::isStale($fixed, '2026-10-30'));
    }
}
