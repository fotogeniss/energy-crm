<?php

/**
 * (352c) Πού επιτρέπεται να μπει ένας χρήστης «χωρίς ομάδα».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Team;

use EnergyCRM\Domain\Team\Placement;
use PHPUnit\Framework\TestCase;

final class PlacementTest extends TestCase
{
    public function testUnderSomeoneOutsideHisOwnTeam(): void
    {
        self::assertTrue(Placement::allowed(5, 2, [1]));
        self::assertTrue(Placement::allowed(5, 2, []));
    }

    public function testNeverUnderHimself(): void
    {
        self::assertFalse(Placement::allowed(5, 5, []));
    }

    /** Ο Συνεργάτης 5 έχει τον 7 από κάτω του: κάτω από τον 7 θα έκανε κύκλο. */
    public function testNeverUnderSomeoneOfHisOwnTeam(): void
    {
        self::assertFalse(Placement::allowed(5, 7, [5]));
        self::assertFalse(Placement::allowed(5, 9, [7, 5, 1]));
    }

    public function testNoIdNoPlace(): void
    {
        self::assertFalse(Placement::allowed(0, 2, []));
        self::assertFalse(Placement::allowed(5, 0, []));
    }
}
