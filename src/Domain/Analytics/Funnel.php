<?php

/**
 * Conversion and cancellation rates over the status counts.
 *
 * Won and lost are defined here rather than inferred at the call site, and the
 * definition changed on 07/09/2026 with the status model: **won** is the
 * payable status plus ΔΙΑΚΟΠΗ, **lost** is the two cancellations. Everything
 * else is still in flight and counts towards neither.
 *
 * ΔΙΑΚΟΠΗ counts as won on purpose. It is terminal, but it is where a contract
 * that *worked* ends up -- it was active, the commission was earned and paid,
 * and the customer later left. Counting it as lost would quietly punish a
 * partner for the passage of time, and would make the conversion rate fall
 * without a single application going wrong.
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

            if ($status->isPayable() || $status === ContractStatus::Terminated) {
                $won += $count;
                continue;
            }

            if ($status->isCancellation()) {
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
