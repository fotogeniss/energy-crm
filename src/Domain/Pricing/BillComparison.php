<?php

/**
 * (328) Όλα τα ενεργά προγράμματα με τιμή, φθηνότερο πρώτο.
 *
 * Ο «Υπολογισμός Προσφοράς» σύγκρινε ένα πρόγραμμα κάθε φορά, με τιμές
 * γραμμένες με το χέρι. Εδώ συγκρίνονται όλα μαζί, για την κατανάλωση που
 * διάβασε το AI από τον λογαριασμό. Προγράμματα χωρίς τιμή δεν μπαίνουν στη
 * σειρά -- ένα «0 €» θα έβγαινε πρώτο -- αλλά επιστρέφονται χωριστά, ώστε η
 * οθόνη να λέει πόσα και ποια λείπουν.
 *
 * Κατηγορία: οικιακός λογαριασμός συγκρίνεται με οικιακά προγράμματα,
 * επαγγελματικός με επαγγελματικά. Ο πελάτης δεν μπορεί να υπογράψει
 * πρόγραμμα άλλης κατηγορίας, άρα μια τέτοια «εξοικονόμηση» δεν πουλιέται.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Pricing;

final class BillComparison
{
    private function __construct()
    {
    }

    /**
     * @param list<array<string, mixed>> $programs Ενεργά προγράμματα (activePrograms()).
     * @param array<int, string>         $providerNames id => όνομα παρόχου.
     * @return array{ranked: list<array<string, mixed>>, unpriced: list<array{id: int, name: string, provider: string}>}
     */
    public static function rank(
        array $programs,
        array $providerNames,
        float $annualKwh,
        ?float $currentAnnual,
        string $energy,
        string $category,
        string $today
    ): array {
        $ranked   = [];
        $unpriced = [];

        foreach ($programs as $program) {
            if ((string) ($program['energy_type'] ?? '') !== $energy) {
                continue;
            }

            if ($category !== '' && (string) ($program['category'] ?? '') !== $category) {
                continue;
            }

            $id       = (int) ($program['id'] ?? 0);
            $provider = $providerNames[(int) ($program['provider_id'] ?? 0)] ?? '';
            $cost     = ProgramPricing::annualCost($program, $annualKwh);

            if ($cost === null) {
                $unpriced[] = ['id' => $id, 'name' => (string) ($program['name'] ?? ''), 'provider' => $provider];
                continue;
            }

            $saving = $currentAnnual !== null ? round($currentAnnual - $cost, 2) : null;

            $ranked[] = [
                'id'            => $id,
                'code'          => (string) ($program['code'] ?? ''),
                'provider_id'   => (int) ($program['provider_id'] ?? 0),
                'provider'      => $provider,
                'name'          => (string) ($program['name'] ?? ''),
                'price_type'    => (string) ($program['price_type'] ?? ''),
                'rule'          => ProgramPricing::rule($program),
                'annual'        => $cost,
                'saving'        => $saving,
                'saving_pct'    => $saving !== null && $currentAnnual > 0
                    ? round(100 * $saving / $currentAnnual, 1)
                    : null,
                'price_updated' => self::date($program['price_updated'] ?? null),
                'stale'         => ProgramPricing::isStale($program, $today),
            ];
        }

        $ranked = self::onePerPackageFamily($ranked, $annualKwh);

        usort(
            $ranked,
            static fn (array $a, array $b): int => [$a['annual'], $a['name']] <=> [$b['annual'], $b['name']]
        );

        return ['ranked' => $ranked, 'unpriced' => $unpriced];
    }

    /**
     * (332) Μία γραμμή ανά οικογένεια πακέτων (Picasso), με το σωστό μέγεθος.
     *
     * Μετά την εκκαθάριση όλα τα μεγέθη κοστίζουν το ίδιο τον χρόνο (PackageFit),
     * άρα εννιά γραμμές Picasso θα έμοιαζαν με εννιά προγράμματα. Μένει το
     * Πακέτο Αναφοράς· αν δεν είναι ενεργό (περιμένει έντυπο), το πλησιέστερο
     * ενεργό, με `fit.reference_available = false` για να το πει η οθόνη.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function onePerPackageFamily(array $rows, float $annualKwh): array
    {
        $out    = [];
        $groups = [];

        foreach ($rows as $row) {
            $familyId = $row['rule']['kind'] === 'package' ? (string) ($row['rule']['family_id'] ?? '') : '';

            if ($familyId === '') {
                $out[] = $row;
                continue;
            }

            $groups[$familyId][] = $row;
        }

        foreach ($groups as $members) {
            $ref = PackageFit::reference($members[0]['rule']['family'], $annualKwh);

            if ($ref === null) {
                continue;
            }

            usort(
                $members,
                static fn (array $a, array $b): int => abs($a['rule']['package_kwh'] - $ref['kwh'])
                    <=> abs($b['rule']['package_kwh'] - $ref['kwh'])
            );

            $best = $members[0];
            $fit  = PackageFit::fit($best['rule'], $annualKwh);

            $best['fit'] = [
                'reference_code'      => $ref['code'],
                'reference_label'     => $ref['label'],
                'reference_available' => $best['code'] === $ref['code'],
                'chosen_label'        => $fit !== null && $fit['chosen'] !== null ? $fit['chosen']['label'] : '',
                'settlement'          => $fit !== null ? $fit['settlement'] : 0.0,
                'sizes'               => array_map(
                    static fn (array $p): array => ['code' => $p['code'], 'label' => $p['label']],
                    $best['rule']['family']
                ),
            ];

            $out[] = $best;
        }

        return $out;
    }

    /**
     * Τι πληρώνει σήμερα ο πελάτης για προμήθεια, από τον λογαριασμό.
     */
    public static function currentAnnual(float $annualKwh, ?float $priceKwh, ?float $fixedMonth): ?float
    {
        if ($priceKwh === null || $priceKwh <= 0 || $annualKwh <= 0) {
            return null;
        }

        return round($annualKwh * $priceKwh + 12 * ($fixedMonth ?? 0.0), 2);
    }

    private static function date(mixed $value): ?string
    {
        $value = is_string($value) ? substr($value, 0, 10) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
