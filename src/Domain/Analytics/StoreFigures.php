<?php

/**
 * (343) Οι μικροί υπολογισμοί της καρτέλας «Κατάστημα» στην Αρχική.
 *
 * Καθαρή αριθμητική, χωρίς WordPress: η σύγκριση με τον προηγούμενο μήνα και
 * το «ποιος πωλητής πόσα» κάτω από κάθε γραμμή του «Θέλει κίνηση».
 * Mockup: docs/UI-STORE-DASHBOARD.html.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Analytics;

final class StoreFigures
{
    private function __construct()
    {
    }

    /**
     * Ποσοστό μεταβολής, στρογγυλεμένο. Null όταν δεν υπάρχει βάση σύγκρισης:
     * από 0 σε 5 δεν είναι «+∞%», είναι «δεν συγκρίνεται».
     */
    public static function change(int $now, int $before): ?int
    {
        if ($before <= 0) {
            return null;
        }

        return (int) round(($now - $before) * 100 / $before);
    }

    /**
     * Πόσα ανά κλειδί (πωλητή ή πάροχο), τα περισσότερα πρώτα.
     *
     * Ισοπαλία: αλφαβητικά, ώστε η σειρά να μην αλλάζει από φόρτωση σε φόρτωση.
     *
     * @param list<string> $keys Ένα στοιχείο ανά εγγραφή.
     *
     * @return list<array{name: string, count: int}>
     */
    public static function split(array $keys): array
    {
        $counts = [];

        foreach ($keys as $key) {
            $key          = $key === '' ? '—' : $key;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $out = [];

        foreach ($counts as $name => $count) {
            $out[] = ['name' => (string) $name, 'count' => $count];
        }

        usort(
            $out,
            static fn (array $a, array $b): int =>
                ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name'])
        );

        return $out;
    }
}
