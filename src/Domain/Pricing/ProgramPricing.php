<?php

/**
 * (328) Πόσο κοστίζει ένα πρόγραμμα τον χρόνο, για δεδομένη κατανάλωση.
 *
 * Μόνο η ΠΡΟΜΗΘΕΙΑ: ενέργεια + πάγιο του προμηθευτή. Ρυθμιζόμενες χρεώσεις,
 * ΥΚΩ, ΕΦΚ, ΦΠΑ και δημοτικά είναι ίδια σε όλους τους παρόχους και δεν
 * αλλάζουν τη σειρά -- το έγραφε ήδη η οθόνη του υπολογισμού.
 *
 * Τρεις κανόνες, γιατί τα προγράμματα της Protergia δεν χωρούν σε μία τιμή:
 *
 * - flat: `price_kwh` × kWh + 12 × `fixed_charge`. Τα περισσότερα.
 * - tiered (Value Balance, Value Power 2.0): κλιμάκια ανά 30 ημέρες. Η ΓΟΣ
 *   του Balance: «Οι πρώτες 0-150 kWh» στην «Αναγωγική Περίοδο Εκκαθάρισης
 *   (30 ημέρες)», και το πάγιο «€/(30 ημέρες)». Σε έναν χρόνο χωρούν 365/30
 *   τέτοιες περίοδοι, όχι 12.
 * - package (Picasso): μηνιαίο πακέτο με ετήσιες kWh. Η αίτηση χωρίζει το
 *   πάγιο Picasso σε «Χρεώσεις Προμήθειας (€/μήνα)» και ρυθμιζόμενες· εδώ
 *   μετρά μόνο το πρώτο, για να συγκρίνεται με τα υπόλοιπα. Πάνω από το
 *   πακέτο: μοναδιαία τιμή μείον την έκπτωση της προωθητικής. Κάτω από το
 *   πακέτο ΔΕΝ υπολογίζεται επιστροφή -- η εκκαθάριση «βάσει Πακέτου
 *   Αναφοράς» της ΓΟΣ δεν είναι απλή αφαίρεση, και μια σύγκριση που
 *   υπόσχεται λιγότερα είναι ασφαλέστερη από μία που υπόσχεται περισσότερα.
 *
 * Χωρίς WordPress: οι κανόνες έρχονται ως πίνακας (στήλη `pricing`, JSON).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Pricing;

final class ProgramPricing
{
    /** Μετά από τόσες μέρες η τιμή θέλει έλεγχο (mockup UI-BILL-ADVISOR). */
    public const STALE_DAYS = 45;

    private const PERIODS_PER_YEAR = 365 / 30;

    private function __construct()
    {
    }

    /**
     * Ο κανόνας του προγράμματος, καθαρισμένος.
     *
     * @param array<string, mixed> $program Γραμμή προγράμματος (price_kwh, fixed_charge, pricing).
     * @return array<string, mixed> Πάντα με `kind`: flat | tiered | package | none.
     */
    public static function rule(array $program): array
    {
        $json = $program['pricing'] ?? null;
        $rule = is_string($json) && $json !== '' ? json_decode($json, true) : (is_array($json) ? $json : null);
        $note = is_array($rule) && is_string($rule['note'] ?? null) ? $rule['note'] : '';

        if (is_array($rule) && ($rule['kind'] ?? '') === 'tiered') {
            $tiers = [];

            foreach ((array) ($rule['tiers'] ?? []) as $tier) {
                if (! is_array($tier) || ! is_numeric($tier[1] ?? null)) {
                    continue;
                }

                $tiers[] = [is_numeric($tier[0] ?? null) ? (float) $tier[0] : null, (float) $tier[1]];
            }

            if ($tiers !== []) {
                return self::withNote([
                    'kind'  => 'tiered',
                    'tiers' => $tiers,
                    'fixed' => self::num($program['fixed_charge'] ?? null) ?? 0.0,
                ], $note);
            }
        }

        if (
            is_array($rule) && ($rule['kind'] ?? '') === 'package'
            && is_numeric($rule['package_kwh'] ?? null)
            && is_numeric($rule['supply_month'] ?? null)
            && is_numeric($rule['excess_kwh'] ?? null)
        ) {
            return self::withNote([
                'kind'         => 'package',
                'package_kwh'  => (float) $rule['package_kwh'],
                'supply_month' => (float) $rule['supply_month'],
                'excess_kwh'   => (float) $rule['excess_kwh'],
            ], $note);
        }

        $price = self::num($program['price_kwh'] ?? null);

        if ($price === null || $price <= 0) {
            return ['kind' => 'none'];
        }

        return self::withNote([
            'kind'      => 'flat',
            'price_kwh' => $price,
            'fixed'     => self::num($program['fixed_charge'] ?? null) ?? 0.0,
        ], $note);
    }

    /**
     * Ετήσιο κόστος προμήθειας σε €, ή null όταν το πρόγραμμα δεν έχει τιμή.
     *
     * @param array<string, mixed> $program
     */
    public static function annualCost(array $program, float $annualKwh): ?float
    {
        $rule = self::rule($program);
        $kwh  = max(0.0, $annualKwh);

        switch ($rule['kind']) {
            case 'flat':
                return round($kwh * (float) $rule['price_kwh'] + 12 * (float) $rule['fixed'], 2);

            case 'tiered':
                /** @var list<array{0: float|null, 1: float}> $tiers */
                $tiers = $rule['tiers'];

                return round(self::tiered($tiers, $kwh) + self::PERIODS_PER_YEAR * (float) $rule['fixed'], 2);

            case 'package':
                $excess = max(0.0, $kwh - (float) $rule['package_kwh']);

                return round(12 * (float) $rule['supply_month'] + $excess * (float) $rule['excess_kwh'], 2);
        }

        return null;
    }

    /**
     * Κατανάλωση περιόδου λογαριασμού → εκτίμηση έτους.
     */
    public static function annualise(float $kwh, int $days): float
    {
        if ($kwh <= 0 || $days <= 0) {
            return 0.0;
        }

        return round($kwh * 365 / $days);
    }

    /**
     * Παλιά τιμή; Σταθερά και πακέτα δεν παλιώνουν -- η τιμή τους είναι
     * γραμμένη στο συμβόλαιο. Κυμαινόμενα/δυναμικά αλλάζουν κάθε μήνα.
     *
     * @param array<string, mixed> $program
     */
    public static function isStale(array $program, string $today): bool
    {
        $type = (string) ($program['price_type'] ?? '');

        if (! in_array($type, ['variable', 'dynamic', 'special'], true)) {
            return false;
        }

        $updated = (string) ($program['price_updated'] ?? '');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}/', $updated)) {
            return true;
        }

        $from = strtotime(substr($updated, 0, 10) . ' 00:00:00 UTC');
        $to   = strtotime($today . ' 00:00:00 UTC');

        if ($from === false || $to === false) {
            return true;
        }

        return ($to - $from) / 86400 > self::STALE_DAYS;
    }

    /**
     * @param list<array{0: float|null, 1: float}> $tiers Όριο ανά 30 ημέρες (null = υπόλοιπο), €/kWh.
     */
    private static function tiered(array $tiers, float $kwh): float
    {
        $cost  = 0.0;
        $left  = $kwh;
        $floor = 0.0;

        foreach ($tiers as [$limit, $price]) {
            if ($left <= 0) {
                break;
            }

            $width = $limit === null ? $left : max(0.0, ($limit - $floor) * self::PERIODS_PER_YEAR);
            $take  = min($left, $width);
            $cost += $take * $price;
            $left -= $take;
            $floor = $limit ?? $floor;
        }

        return $cost;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private static function withNote(array $rule, string $note): array
    {
        if ($note !== '') {
            $rule['note'] = $note;
        }

        return $rule;
    }

    private static function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
