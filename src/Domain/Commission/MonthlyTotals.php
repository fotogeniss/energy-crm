<?php

/**
 * Commission grouped by month, newest first, with Greek month names.
 *
 * Pure aggregation over rows already fetched — no database, no WordPress — so
 * the rounding and the ordering can be stated in tests rather than trusted.
 *
 * ## Το `paid` ανά μήνα, από 22/08
 *
 * Ο πίνακας της οθόνης είχε στήλη «Κατάσταση» που τύπωνε **«Καταχωρημένο» σε
 * κάθε γραμμή**, σταθερά, ενώ οι γραμμές είναι ημερολογιακοί μήνες και ο
 * πίνακας λέγεται «Οι εκκαθαρίσεις μου». Ο συνεργάτης που έβλεπε «Ιούλιος ·
 * Καταχωρημένο» έβγαζε συμπέρασμα για την πληρωμή του Ιουλίου· η στήλη δεν
 * έλεγε τίποτα. Τα δεδομένα υπήρχαν ήδη — ο controller υπολόγιζε `payout_status`
 * ανά σύμβαση και το πετούσε.
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

    // phpcs:disable Generic.Files.LineLength.TooLong -- PHPStan array-shape type; wrapping it would break the annotation.
    /**
     * @param list<array{month: string, amount: float, paid?: bool}> $entries A month as 'YYYY-MM'.
     *
     * @return array{months: list<array{label: string, count: int, amount: float, paid: int}>, best: float, best_label: string}
     */
    // phpcs:enable Generic.Files.LineLength.TooLong
    public static function from(array $entries): array
    {
        $totals = [];

        foreach ($entries as $entry) {
            $key = $entry['month'];

            $totals[$key] ??= ['count' => 0, 'amount' => 0.0, 'paid' => 0];
            $totals[$key]['count']++;
            $totals[$key]['amount'] += $entry['amount'];

            // Πλήθος, όχι σημαία. Ένας μήνας μπορεί να είναι μισοπληρωμένος, και
            // «πληρωμένος/απλήρωτος» θα έπρεπε να διαλέξει ψέμα. Με τον αριθμό
            // δίπλα στο σύνολο, η οθόνη λέει «2 από 5» — ο ίδιος κανόνας με την
            // καρτέλα συνεργάτη: κάθε νούμερο κουβαλά τον παρονομαστή του.
            if (! empty($entry['paid'])) {
                $totals[$key]['paid']++;
            }
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
                'paid'   => $total['paid'],
            ];

            if ($total['amount'] > $best) {
                $best      = $total['amount'];
                $bestLabel = $name;
            }
        }

        return ['months' => $months, 'best' => round($best, 2), 'best_label' => $bestLabel];
    }
}
