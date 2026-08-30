<?php

/**
 * The level a partner has reached this month, and what the next one costs.
 *
 * Pure thresholds, no database. Extracted mostly so the boundaries can be
 * stated once and tested: an agent on exactly fifteen contracts is Bronze, not
 * "almost Bronze", and the sort of off-by-one nobody notices in a loop is
 * precisely what makes someone feel cheated.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Partner;

final class PerformanceTier
{
    /** @var array<string, int> */
    private const TIERS = [
        'Bronze'   => 15,
        'Silver'   => 40,
        'Gold'     => 80,
        'Platinum' => 150,
        'Diamond'  => 300,
    ];

    private const NONE = 'Χωρίς level';

    private function __construct()
    {
    }

    /**
     * AUDIT 30/08: `$next`/`$nextAt` started pre-seeded with the FIRST tier
     * (Bronze/15) and were only ever overwritten inside the loop's "not yet
     * there" branch. That branch never runs once every tier is reached --
     * a partner on 300+ contracts (Diamond, the top tier) got back
     * `next: 'Bronze', next_at: 15`, already exceeded by a factor of 20, with
     * `remaining` clamped to 0 by the `max(0, ...)` below. The dashboard card
     * (`ecrm-view-dashboard.js`, `levelHTML()`) would have rendered "0 ακόμα
     * για Bronze" under someone's Diamond badge -- harmless-looking because
     * `remaining` happened to floor at zero, but the label naming an already-
     * surpassed tier was still wrong.
     *
     * Fixed by seeding `$next`/`$nextAt` with the neutral "no next tier"
     * values instead of the first tier's. At the top, the loop's branch never
     * runs and they are left exactly there -- which `levelHTML()` already
     * treats correctly: an empty `next` hides the "X ακόμα για..." line and
     * the scale row, an empty `next_at` collapses the meter to 0%. No frontend
     * change needed; the empty/zero case was already handled, just never
     * reached from here.
     *
     * @return array{current: string, next: string, next_at: int, remaining: int}
     */
    public static function forVolume(int $contractsThisMonth): array
    {
        $current = self::NONE;
        $next    = '';
        $nextAt  = 0;

        foreach (self::TIERS as $name => $threshold) {
            if ($contractsThisMonth >= $threshold) {
                $current = $name;
                continue;
            }

            $next   = $name;
            $nextAt = $threshold;
            break;
        }

        return [
            'current'   => $current,
            'next'      => $next,
            'next_at'   => $nextAt,
            'remaining' => max(0, $nextAt - $contractsThisMonth),
        ];
    }
}
