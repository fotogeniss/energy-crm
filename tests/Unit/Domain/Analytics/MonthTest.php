<?php

/**
 * (345) Ημερολογιακοί μήνες χωρίς ρολόι.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Analytics;

use EnergyCRM\Domain\Analytics\Month;
use PHPUnit\Framework\TestCase;

final class MonthTest extends TestCase
{
    public function testOnlyRealMonthsParse(): void
    {
        self::assertSame('2026-10', Month::parse('2026-10'));
        self::assertNull(Month::parse('2026-13'));
        self::assertNull(Month::parse('2026-1'));
        self::assertNull(Month::parse('2026-10-01'));
        self::assertNull(Month::parse("2026-10' OR 1=1"));
    }

    public function testYearBoundaries(): void
    {
        self::assertSame('2025-12', Month::previous('2026-01'));
        self::assertSame('2027-01', Month::next('2026-12'));
        self::assertSame(['2026-02', '2026-01', '2025-12'], Month::recent('2026-02', 3));
    }

    public function testDistance(): void
    {
        self::assertSame(1, Month::distance('2026-12', '2027-01'));
        self::assertSame(-2, Month::distance('2026-10', '2026-08'));
        self::assertSame(0, Month::distance('2026-10', '2026-10'));
    }

    public function testGreekNames(): void
    {
        self::assertSame('Οκτώβριος 2026', Month::label('2026-10'));
        self::assertSame('Μάιος', Month::name('2026-05'));
        self::assertSame('Σεπτέμβριο', Month::accusative('2026-09'));
        self::assertSame('2026-10-01', Month::firstDay('2026-10'));
    }
}
