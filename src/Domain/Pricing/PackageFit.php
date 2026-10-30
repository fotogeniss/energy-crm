<?php

/**
 * (332) Picasso: Πακέτο Αναφοράς, κόστος έτους και σωστό μέγεθος.
 *
 * ΓΟΣ Picasso, Ειδικός Όρος 2 (εκκαθάριση): «Πακέτο Αναφοράς ορίζεται ως το
 * πλησιέστερο εκ των Πακέτων Κατανάλωσης, του οποίου η Ετήσια Κατανάλωση …
 * υπολείπεται» της κατανάλωσης. Στην εκκαθάριση:
 *
 * (β) χρεώνεται/πιστώνεται η διαφορά «Μηνιαία Πάγια Χρέωση Picasso του Πακέτου
 *     Αναφοράς × 12 μείον … του Επιλεγέντος Πακέτου × 12»·
 * (γ) υπερβάλλουσα πάνω από το Πακέτο Αναφοράς: Μοναδιαία Σταθερή Τιμή
 *     Προμήθειας (με την έκπτωση της Προωθητικής Ενέργειας στην αίτηση)·
 * (δ) υπολειπόμενη (μόνο κάτω από το μικρότερο πακέτο): πίστωση με τη
 *     Μοναδιαία Σταθερή Τιμή.
 *
 * Άρα το κόστος του χρόνου ΔΕΝ εξαρτάται από το μέγεθος που υπογράφεται· το
 * μέγεθος αλλάζει μόνο τη μηνιαία δόση και το ποσό της εκκαθάρισης.
 *
 * Δύο σημεία της ΓΟΣ δεν είναι σαφή και υπολογίζονται συντηρητικά: η
 * «Υπερβάλλουσα Ποσότητα 5%» δεν αφαιρείται· η έκπτωση της υπερβάλλουσας
 * εφαρμόζεται, όπως είναι τυπωμένη στην αίτηση.
 *
 * Μόνο προμήθεια, όπως όλη η σύγκριση (ProgramPricing).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Pricing;

final class PackageFit
{
    private function __construct()
    {
    }

    /**
     * Καθαρίζει τον πίνακα πακέτων, μικρότερο πρώτο.
     *
     * @return list<array{code: string, label: string, kwh: float, supply_month: float, price: float}>
     */
    public static function family(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $row) {
            if (
                ! is_array($row)
                || ! is_numeric($row['kwh'] ?? null)
                || ! is_numeric($row['supply_month'] ?? null)
                || ! is_numeric($row['price'] ?? null)
            ) {
                return [];
            }

            $out[] = [
                'code'         => (string) ($row['code'] ?? ''),
                'label'        => (string) ($row['label'] ?? ''),
                'kwh'          => (float) $row['kwh'],
                'supply_month' => (float) $row['supply_month'],
                'price'        => (float) $row['price'],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['kwh'] <=> $b['kwh']);

        return $out;
    }

    /**
     * Το Πακέτο Αναφοράς: το μεγαλύτερο που χωράει στην κατανάλωση· αν δεν
     * χωράει κανένα, το μικρότερο.
     *
     * @param list<array{code: string, label: string, kwh: float, supply_month: float, price: float}> $family
     * @return array{code: string, label: string, kwh: float, supply_month: float, price: float}|null
     */
    public static function reference(array $family, float $annualKwh): ?array
    {
        if ($family === []) {
            return null;
        }

        $ref = $family[0];

        foreach ($family as $package) {
            if ($package['kwh'] <= $annualKwh) {
                $ref = $package;
            }
        }

        return $ref;
    }

    /**
     * Ετήσια προμήθεια μετά την εκκαθάριση.
     *
     * @param array<string, mixed> $rule Κανόνας `package` με `family` (ProgramPricing::rule()).
     */
    public static function annualSupply(array $rule, float $annualKwh): ?float
    {
        /** @var list<array{code: string, label: string, kwh: float, supply_month: float, price: float}> $family */
        $family = $rule['family'] ?? [];
        $ref    = self::reference($family, $annualKwh);

        if ($ref === null) {
            return null;
        }

        $cost   = 12 * $ref['supply_month'];
        $excess = max(0.0, $annualKwh - $ref['kwh']);
        $short  = max(0.0, $ref['kwh'] - $annualKwh);

        $cost += $excess * (float) $rule['excess_kwh'];
        $cost -= $short * (float) ($rule['unit_kwh'] ?? 0);

        return round(max(0.0, $cost), 2);
    }

    /**
     * Το σωστό μέγεθος για την κατανάλωση, και τι γίνεται με αυτό που διαλέχτηκε.
     *
     * @param array<string, mixed> $rule Κανόνας `package` με `family`.
     * @return array{
     *   reference: array{code: string, label: string, kwh: float, supply_month: float, price: float},
     *   chosen: array{code: string, label: string, kwh: float, supply_month: float, price: float}|null,
     *   fits: bool,
     *   settlement: float
     * }|null
     *         `settlement`: ποσό εκκαθάρισης τον χρόνο για το επιλεγμένο (+ χρέωση, − επιστροφή).
     */
    public static function fit(array $rule, float $annualKwh): ?array
    {
        /** @var list<array{code: string, label: string, kwh: float, supply_month: float, price: float}> $family */
        $family = $rule['family'] ?? [];
        $ref    = self::reference($family, $annualKwh);

        if ($ref === null || $annualKwh <= 0) {
            return null;
        }

        $code   = (string) ($rule['code'] ?? '');
        $chosen = null;

        foreach ($family as $package) {
            if ($package['code'] === $code) {
                $chosen = $package;
            }
        }

        return [
            'reference'  => $ref,
            'chosen'     => $chosen,
            'fits'       => $chosen !== null && $chosen['code'] === $ref['code'],
            'settlement' => $chosen === null ? 0.0 : round(12 * ($ref['price'] - $chosen['price']), 2),
        ];
    }
}
