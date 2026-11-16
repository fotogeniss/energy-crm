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
     * (345) Πίσω από τον ρυθμό του μήνα: λιγότερα από το κομμάτι του στόχου
     * που αντιστοιχεί στις μέρες που πέρασαν. Στις 15 ενός 30ήμερου μήνα,
     * κάτω από το μισό. Χωρίς στόχο, ποτέ.
     */
    public static function behindPace(int $done, ?int $target, int $day, int $daysInMonth): bool
    {
        if ($target === null || $target <= 0 || $daysInMonth <= 0) {
            return false;
        }

        $day = max(0, min($day, $daysInMonth));

        return $done * $daysInMonth < $target * $day;
    }

    /** (345) Ποσοστό του στόχου, στρογγυλεμένο· null χωρίς στόχο. */
    public static function ofTarget(int $done, ?int $target): ?int
    {
        return $target === null || $target <= 0 ? null : (int) round($done * 100 / $target);
    }

    /**
     * (403) Το ποσοστό στόχου της ομάδας: μόνο όσοι ΕΧΟΥΝ στόχο, στον
     * αριθμητή και στον παρονομαστή. Πριν, οι αιτήσεις ΟΛΩΝ διαιρούνταν με
     * τους στόχους μόνο όσων είχαν: ο Α με στόχο 10 και 2 αιτήσεις και ο Β
     * χωρίς στόχο με 9 έβγαζαν «110%» ενώ ο μόνος στόχος ήταν στο 20%.
     *
     * @param array<int, int> $doneById    αιτήσεις ανά χρήστη
     * @param array<int, int> $targetsById στόχος ανά χρήστη (μόνο όσοι έχουν)
     */
    public static function teamOfTarget(array $doneById, array $targetsById): ?int
    {
        $done   = 0;
        $target = 0;

        foreach ($targetsById as $id => $goal) {
            if ($goal <= 0) {
                continue;
            }

            $target += $goal;
            $done   += (int) ($doneById[$id] ?? 0);
        }

        return $target > 0 ? self::ofTarget($done, $target) : null;
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
