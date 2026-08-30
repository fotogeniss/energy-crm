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

    /**
     * AUDIT 30/08: this test's own name promised "nothing left to reach", but
     * it never actually checked `next`/`next_at` -- only `remaining`, which
     * happened to floor at 0 through `max(0, ...)` even while `next` was
     * silently naming an already-surpassed tier (Bronze, at 15, for someone
     * on 500). Asserting on `next`/`next_at` here is what the test's name
     * already claimed to cover.
     */
    public function testTheTopLevelHasNothingLeftToReach(): void
    {
        $tier = PerformanceTier::forVolume(500);

        self::assertSame('Diamond', $tier['current']);
        self::assertSame('', $tier['next'], 'No tier above Diamond -- must not name an already-surpassed one.');
        self::assertSame(0, $tier['next_at']);
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
