<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Partner;

use EnergyCRM\Domain\Partner\PerformanceTier;
use PHPUnit\Framework\TestCase;

final class PerformanceTierTest extends TestCase
{
    public function testNoContractsMeansNoLevelYet(): void
    {
        $tier = PerformanceTier::forVolume(0);

        self::assertSame('Χωρίς level', $tier['current']);
        self::assertSame('Bronze', $tier['next']);
        self::assertSame(15, $tier['remaining']);
    }

    /** Exactly on the threshold has reached it, not "almost". */
    public function testTheThresholdItselfCounts(): void
    {
        self::assertSame('Bronze', PerformanceTier::forVolume(15)['current']);
        self::assertSame('Silver', PerformanceTier::forVolume(40)['current']);
    }

    public function testOneShortIsStillTheLevelBelow(): void
    {
        $tier = PerformanceTier::forVolume(39);

        self::assertSame('Bronze', $tier['current']);
        self::assertSame('Silver', $tier['next']);
        self::assertSame(1, $tier['remaining']);
    }

    public function testTheTopLevelHasNothingLeftToReach(): void
    {
        $tier = PerformanceTier::forVolume(500);

        self::assertSame('Diamond', $tier['current']);
        self::assertSame(0, $tier['remaining']);
    }

    public function testRemainingIsNeverNegative(): void
    {
        foreach ([0, 1, 14, 15, 41, 299, 300, 1000] as $volume) {
            self::assertGreaterThanOrEqual(
                0,
                PerformanceTier::forVolume($volume)['remaining'],
                'volume ' . $volume
            );
        }
    }
}
