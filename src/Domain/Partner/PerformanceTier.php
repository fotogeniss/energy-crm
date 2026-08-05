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
     * @return array{current: string, next: string, next_at: int, remaining: int}
     */
    public static function forVolume(int $contractsThisMonth): array
    {
        $current = self::NONE;
        $next    = array_key_first(self::TIERS);
        $nextAt  = self::TIERS[$next];

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
