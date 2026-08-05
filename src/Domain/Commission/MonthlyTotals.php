<?php

/**
 * Commission grouped by month, newest first, with Greek month names.
 *
 * Pure aggregation over rows already fetched — no database, no WordPress — so
 * the rounding and the ordering can be stated in tests rather than trusted.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Commission;

final class MonthlyTotals
{
    /** @var array<int, string> */
    private const MONTHS = [
        1  => 'Ιανουάριος',
        2  => 'Φεβρουάριος',
        3  => 'Μάρτιος',
        4  => 'Απρίλιος',
        5  => 'Μάιος',
        6  => 'Ιούνιος',
        7  => 'Ιούλιος',
        8  => 'Αύγουστος',
        9  => 'Σεπτέμβριος',
        10 => 'Οκτώβριος',
        11 => 'Νοέμβριος',
        12 => 'Δεκέμβριος',
    ];

    private function __construct()
    {
    }

    /**
     * @param list<array{month: string, amount: float}> $entries A month as 'YYYY-MM'.
     *
     * @return array{months: list<array{label: string, count: int, amount: float}>, best: float, best_label: string}
     */
    public static function from(array $entries): array
    {
        $totals = [];

        foreach ($entries as $entry) {
            $key = $entry['month'];

            $totals[$key] ??= ['count' => 0, 'amount' => 0.0];
            $totals[$key]['count']++;
            $totals[$key]['amount'] += $entry['amount'];
        }

        // Newest first: the month a partner cares about is the current one.
        krsort($totals);

        $months    = [];
        $best      = 0.0;
        $bestLabel = '';

        foreach ($totals as $key => $total) {
            $number = (int) substr((string) $key, 5, 2);
            $name   = self::MONTHS[$number] ?? (string) $key;

            $months[] = [
                'label'  => $name . ' ' . substr((string) $key, 0, 4),
                'count'  => $total['count'],
                'amount' => round($total['amount'], 2),
            ];

            if ($total['amount'] > $best) {
                $best      = $total['amount'];
                $bestLabel = $name;
            }
        }

        return ['months' => $months, 'best' => round($best, 2), 'best_label' => $bestLabel];
    }
}
