<?php

/**
 * Conversion and cancellation rates over the status counts.
 *
 * Won and lost are defined here rather than inferred at the call site: a
 * contract that is routed, active or resolved has earned its commission, and
 * one that is cancelled or terminated has not. Everything else is still in
 * flight and counts towards neither.
 *
 * Pure arithmetic, tested — these percentages end up in front of partners
 * comparing themselves to each other.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Analytics;

use EnergyCRM\Domain\Contract\ContractStatus;

final class Funnel
{
    private function __construct()
    {
    }

    /**
     * @param array<string, int> $countsByStatus
     *
     * @return array{
     *     total: int, won: int, lost: int,
     *     conv_rate: float, canc_rate: float,
     *     funnel: list<array{status: string, label: string, count: int}>
     * }
     */
    public static function from(array $countsByStatus): array
    {
        $total = array_sum($countsByStatus);
        $won   = 0;
        $lost  = 0;
        $steps = [];

        foreach (ContractStatus::cases() as $status) {
            $count = $countsByStatus[$status->value] ?? 0;

            $steps[] = [
                'status' => $status->value,
                'label'  => $status->label(),
                'count'  => $count,
            ];

            if ($status->isPayable()) {
                $won += $count;
                continue;
            }

            if ($status->isTerminal()) {
                $lost += $count;
            }
        }

        return [
            'total'     => $total,
            'won'       => $won,
            'lost'      => $lost,
            'conv_rate' => self::percentage($won, $total),
            'canc_rate' => self::percentage($lost, $total),
            'funnel'    => $steps,
        ];
    }

    private static function percentage(int $part, int $whole): float
    {
        return $whole > 0 ? round(100 * $part / $whole, 1) : 0.0;
    }
}
