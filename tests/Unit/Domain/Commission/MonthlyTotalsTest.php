<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Commission;

use EnergyCRM\Domain\Commission\MonthlyTotals;
use PHPUnit\Framework\TestCase;

final class MonthlyTotalsTest extends TestCase
{
    public function testItSumsAndCountsPerMonth(): void
    {
        $result = MonthlyTotals::from([
            ['month' => '2026-03', 'amount' => 10.0],
            ['month' => '2026-03', 'amount' => 15.5],
            ['month' => '2026-02', 'amount' => 20.0],
        ]);

        self::assertSame('Μάρτιος 2026', $result['months'][0]['label']);
        self::assertSame(2, $result['months'][0]['count']);
        self::assertSame(25.5, $result['months'][0]['amount']);
    }

    public function testTheNewestMonthComesFirst(): void
    {
        $result = MonthlyTotals::from([
            ['month' => '2025-12', 'amount' => 1.0],
            ['month' => '2026-01', 'amount' => 1.0],
            ['month' => '2026-11', 'amount' => 1.0],
        ]);

        self::assertSame(
            ['Νοέμβριος 2026', 'Ιανουάριος 2026', 'Δεκέμβριος 2025'],
            array_column($result['months'], 'label')
        );
    }

    public function testTheBestMonthIsTheLargestSumNotTheLargestSingleAmount(): void
    {
        $result = MonthlyTotals::from([
            ['month' => '2026-01', 'amount' => 100.0],
            ['month' => '2026-02', 'amount' => 60.0],
            ['month' => '2026-02', 'amount' => 60.0],
        ]);

        self::assertSame('Φεβρουάριος', $result['best_label']);
        self::assertSame(120.0, $result['best']);
    }

    public function testAmountsAreRoundedToCents(): void
    {
        $result = MonthlyTotals::from([
            ['month' => '2026-01', 'amount' => 0.1],
            ['month' => '2026-01', 'amount' => 0.2],
        ]);

        self::assertSame(0.3, $result['months'][0]['amount']);
    }

    public function testNothingEarnedYieldsNoMonthsAndNoBest(): void
    {
        $result = MonthlyTotals::from([]);

        self::assertSame([], $result['months']);
        self::assertSame(0.0, $result['best']);
        self::assertSame('', $result['best_label']);
    }
}
