<?php

/**
 * (403) Η «Υγεία» συγκρίνει την ώρα της βάσης με την ώρα του WordPress: οι
 * αιτήσεις γράφουν ώρα βάσης, η Αρχική μετρά «σήμερα/μήνας» με ώρα WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use EnergyCRM\Infrastructure\HealthChecks;
use PHPUnit\Framework\TestCase;

final class ClockDriftTest extends TestCase
{
    public function testTheSameTimeIsNoDrift(): void
    {
        self::assertSame(0, HealthChecks::clockDriftMinutes('2026-11-16 12:00:00', '2026-11-16 12:00:20'));
    }

    public function testAthensAgainstUtcIsTwoHoursEitherWay(): void
    {
        self::assertSame(120, HealthChecks::clockDriftMinutes('2026-11-16 10:00:00', '2026-11-16 12:00:00'));
        self::assertSame(120, HealthChecks::clockDriftMinutes('2026-11-16 12:00:00', '2026-11-16 10:00:00'));
    }

    public function testAcrossMidnightAndTheMonthBoundary(): void
    {
        self::assertSame(180, HealthChecks::clockDriftMinutes('2026-11-30 22:30:00', '2026-12-01 01:30:00'));
    }

    public function testUnreadableTimeIsNull(): void
    {
        self::assertNull(HealthChecks::clockDriftMinutes('', '2026-11-16 12:00:00'));
        self::assertNull(HealthChecks::clockDriftMinutes('όχι ώρα', '2026-11-16 12:00:00'));
    }
}
