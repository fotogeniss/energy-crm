<?php

/**
 * (335) «Ανανέωση με πρόταση»: υπάρχει σήμερα κάτι φθηνότερο για αυτόν τον πελάτη;
 *
 * Για μια σύμβαση που λήγει: το φθηνότερο πρόγραμμα της ίδιας ενέργειας και
 * κατηγορίας για την ετήσια κατανάλωσή του (ίδιος υπολογισμός με την
 * «Πρόταση από λογαριασμό» -- BillComparison, ProgramPricing, PackageFit),
 * και πόσο κοστίζει το δικό του πρόγραμμα με τις σημερινές τιμές.
 *
 * Κυμαινόμενα με «παλιά τιμή» (πάνω από 45 μέρες) δεν προτείνονται: μια
 * ανανέωση στηριγμένη σε τιμή που ίσως δεν ισχύει είναι υπόσχεση που δεν
 * κρατιέται. Αν η τιμή του δικού του προγράμματος δεν είναι γνωστή, δίνεται
 * μόνο το φθηνότερο, χωρίς εξοικονόμηση.
 *
 * Mockup: docs/UI-RENEWAL-OFFER.html.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Pricing;

final class RenewalOffer
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>|null  $current  Το πρόγραμμα της σύμβασης (μπορεί να μην είναι πια ενεργό).
     * @param list<array<string, mixed>> $programs Ενεργά προγράμματα που βλέπει ο χρήστης.
     * @return array{
     *   state: string, best: array<string, mixed>|null, best_annual: float|null,
     *   current_annual: float|null, saving: float|null
     * }
     *         state: `cheaper` | `best` (ήδη το φθηνότερο) | `none` (τίποτα να συγκριθεί).
     */
    public static function for(
        ?array $current,
        array $programs,
        float $annualKwh,
        string $energy,
        string $category,
        string $today
    ): array {
        $none = ['state' => 'none', 'best' => null, 'best_annual' => null, 'current_annual' => null, 'saving' => null];

        if ($annualKwh <= 0) {
            return $none;
        }

        $currentAnnual = $current !== null ? ProgramPricing::annualCost($current, $annualKwh) : null;
        $ranked        = BillComparison::rank(
            $programs,
            [],
            $annualKwh,
            $currentAnnual,
            $energy,
            $category,
            $today
        )['ranked'];
        $fresh         = array_values(array_filter($ranked, static fn (array $row): bool => ! $row['stale']));

        if ($fresh === []) {
            return $none;
        }

        $best = $fresh[0];

        // Το δικό του πρόγραμμα είναι το φθηνότερο -- ή κοστίζει το ίδιο ή
        // λιγότερο από το πρώτο της λίστας (π.χ. ανενεργό πια, με καλή τιμή).
        $currentId = $current !== null ? (int) ($current['id'] ?? 0) : 0;

        if (
            ($currentId > 0 && (int) $best['id'] === $currentId)
            || ($currentAnnual !== null && $currentAnnual <= (float) $best['annual'])
        ) {
            return [
                'state'          => 'best',
                'best'           => null,
                'best_annual'    => null,
                'current_annual' => $currentAnnual,
                'saving'         => null,
            ];
        }

        return [
            'state'          => 'cheaper',
            'best'           => [
                'id'          => (int) $best['id'],
                'name'        => (string) $best['name'],
                'provider_id' => (int) $best['provider_id'],
            ],
            'best_annual'    => (float) $best['annual'],
            'current_annual' => $currentAnnual,
            'saving'         => $currentAnnual !== null ? round($currentAnnual - (float) $best['annual'], 2) : null,
        ];
    }

    /** Η ετήσια κατανάλωση από τα extras της σύμβασης, ή null. */
    public static function kwhFrom(mixed $extraJson): ?float
    {
        $extra = is_string($extraJson) && $extraJson !== ''
            ? json_decode($extraJson, true)
            : (is_array($extraJson) ? $extraJson : null);
        $value = is_array($extra) ? ($extra['annual_kwh'] ?? null) : null;

        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
