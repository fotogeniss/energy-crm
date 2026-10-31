<?php

/**
 * (345) Το email της 1ης του μήνα: «Οκτώβριος 2026 σε μια ματιά».
 *
 * Μόνο αριθμοί και ονόματα πωλητών. Χτίζεται από τον ίδιο πίνακα με την
 * οθόνη (Infrastructure\StoreMonthReport::build()).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Analytics;

final class MonthlySummary
{
    private function __construct()
    {
    }

    /** @param array<string, mixed> $report */
    public static function subject(array $report): string
    {
        return (string) $report['label'] . ' σε μια ματιά';
    }

    /**
     * Οι γραμμές του σώματος, χωρίς χαιρετισμό ή υπογραφή.
     *
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    public static function lines(array $report): array
    {
        $t     = (array) $report['tiles'];
        $prev  = (string) $report['previous'];
        $lines = [];

        $apps = 'Αιτήσεις: ' . (int) $t['applications'];
        $more = [];

        if ($t['change'] !== null) {
            $more[] = self::signed((int) $t['change']) . '% από ' . $prev;
        }

        if ($t['target'] !== null) {
            $more[] = 'στόχος ' . (int) $t['target'] . ' · ' . (int) $t['of_target'] . '%';
        }

        $lines[] = $apps . ($more !== [] ? ' (' . implode(', ', $more) . ')' : '');

        $lost    = 'Απορρίψεις/ακυρώσεις: ' . (int) $t['lost'];
        $reason  = mb_strtolower((string) $t['top_reason'], 'UTF-8');
        $lost   .= $reason !== '' ? ' (κυρίως: ' . $reason . ')' : '';
        $lines[] = 'Έγιναν ενεργές: ' . (int) $t['activated'] . ' · ' . $lost;

        if ((int) $t['leads'] > 0) {
            $lines[] = 'Υποψήφιοι: ' . (int) $t['leads'] . ' · έγιναν αίτηση: ' . (int) $t['leads_converted']
                . ((int) $t['leads_qr'] > 0 ? ' · από QR code: ' . (int) $t['leads_qr'] : '');
        }

        $members = (array) $report['members'];

        if ($members !== [] && (int) $members[0]['applications'] > 0) {
            $lines[] = 'Πρώτη θέση: ' . $members[0]['name'] . ' ' . (int) $members[0]['applications'];
        }

        $behind = array_values(array_filter(
            $members,
            static fn (array $m): bool => $m['target'] !== null && $m['applications'] < $m['target']
        ));

        if ($behind !== []) {
            $lines[] = 'Κάτω από τον στόχο: ' . implode(', ', array_map(
                static fn (array $m): string => $m['name'] . ' ' . (int) $m['applications'] . '/' . (int) $m['target'],
                $behind
            ));
        }

        return $lines;
    }

    private static function signed(int $n): string
    {
        return $n > 0 ? '+' . $n : (string) $n;
    }
}
