<?php

/**
 * (328) Οι σταθερές τιμές της Protergia, από τις αιτήσεις 22/10 και 29/10/2026.
 *
 * Μόνο ό,τι είναι τυπωμένο στο έντυπο: σταθερά, κλιμακωτά και πακέτα. Τα
 * κυμαινόμενα (Standard, Special, Simple, Student, Gas) και τα δυναμικά
 * (Dynamic One) έχουν τύπο με ΤΕΑ Αναφοράς ή μηχανισμό διακύμανσης -- η τιμή
 * τους αλλάζει κάθε μήνα και τη γράφει ο διαχειριστής στη σελίδα Παρόχων.
 *
 * Πηγές (σελ. 1 κάθε αίτησης, «ΙΔΙΑΙΤΕΡΑ ΧΑΡΑΚΤΗΡΙΣΤΙΚΑ ΤΙΜΟΛΟΓΙΟΥ»):
 * - Value Sure 12M 4.0: 247 €/MWh, «177,00 €/MWh μετά την Έκπτωση Συνέπειας»,
 *   πάγιο 11,90 €/μήνα.
 * - Value Balance 12M: 139 €/MWh έως 150 kWh, 219 από 151, πάγιο 10,90.
 *   Επαγγελματικό Ε1: 159 έως 250, 219 από 251, πάγιο 12,90.
 * - Value Power 2.0 (Ε2): 184,90 έως 1000 kWh, 214,90 από 1001, πάγιο 14,90.
 * - Value Sure 12M Ε3 2.0: 269 €/MWh με έκπτωση συνέπειας 69,1, πάγιο 0.
 * - Picasso: πίνακας «ΠΑΚΕΤΑ ΚΑΤΑΝΑΛΩΣΗΣ» -- ετήσια kWh και «ΧΡΕΩΣΕΙΣ
 *   ΠΡΟΜΗΘΕΙΑΣ (€/ΜΗΝΑ)». Υπερβάλλουσα: οικιακά 0,174 − 0,075 έκπτωση,
 *   επαγγελματικά 0,189 − 0,060.
 *
 * Σταθερά με έκπτωση συνέπειας: μπαίνει η τιμή ΜΕ την έκπτωση, γιατί αυτή
 * πληρώνει ο πελάτης που εξοφλεί εμπρόθεσμα, και η σημείωση το λέει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Pricing;

final class ProtergiaPrices2026
{
    /** Ημερομηνία των εντύπων από τα οποία πάρθηκαν. */
    public const AS_OF = '2026-10-29';

    private const HOME_EXCESS = 0.099;

    private const BIZ_EXCESS = 0.129;

    /** Μοναδιαία Σταθερή Τιμή Προμήθειας Picasso (οικιακά / επαγγελματικά). */
    private const HOME_UNIT = 0.174;

    private const BIZ_UNIT = 0.189;

    /**
     * Ίδια πακέτα kWh σε οικιακά και επαγγελματικά, άλλες χρεώσεις:
     * [kWh/έτος, προμήθεια οικ. €/μήνα, προμήθεια επαγ. €/μήνα, πάγιο Picasso οικ., πάγιο Picasso επαγ.].
     * Το «πάγιο Picasso» είναι το ποσό του μήνα (προμήθεια + ρυθμιζόμενες)·
     * με αυτό γίνεται η εκκαθάριση με το Πακέτο Αναφοράς (332).
     */
    private const PACKAGES = [
        's1' => [1325, 21.50, 21.01, 39.90, 49.90],
        's2' => [1875, 29.71, 33.81, 49.90, 64.90],
        's3' => [2700, 42.03, 48.51, 64.90, 82.90],
        'm1' => [3550, 57.27, 62.12, 82.90, 99.90],
        'm2' => [4600, 73.86, 82.92, 102.90, 124.90],
        'm3' => [6000, 97.00, 107.32, 134.90, 154.90],
        'l1' => [11000, 173.22, 187.32, 259.90, 254.90],
        'l2' => [15300, 231.27, 260.13, 359.90, 344.90],
        'l3' => [20200, 303.46, 340.53, 479.90, 444.90],
    ];

    private function __construct()
    {
    }

    /**
     * (331) Ρήτρα πρόωρης αποχώρησης, € ανά μήνα αποχώρησης (1ος…12ος).
     *
     * Από το «Ρήτρα Πρόωρης Αποχώρησης» κάθε αίτησης. Κυμαινόμενα, δυναμικά
     * και αέριο δεν έχουν.
     *
     * @return array<string, list<int>>
     */
    public static function exitFees(): array
    {
        $sure    = [180, 180, 180, 135, 135, 135, 90, 90, 90, 45, 45, 0];
        $biz     = [220, 220, 220, 165, 165, 165, 110, 110, 110, 55, 55, 0];
        $picasso = [120, 110, 100, 90, 80, 70, 60, 50, 40, 30, 20, 0];

        $out = [
            'protergia_value_sure4_12m'   => $sure,
            'protergia_value_balance_12m' => $sure,
            'protergia_epag1_balance_12m' => $biz,
            'protergia_epag2_power2'      => $biz,
            'protergia_epag3_sure12m_2'   => [180, 166, 152, 138, 124, 110, 95, 80, 65, 50, 35, 0],
            'protergia_picasso2'          => $picasso,
            'protergia_picasso2_student'  => $picasso,
        ];

        foreach (array_keys(self::PACKAGES) as $size) {
            $out['protergia_pic_h_' . $size] = $picasso;
            $out['protergia_pic_b_' . $size] = $picasso;
        }

        return $out;
    }

    /**
     * code => [price_kwh, fixed_charge, pricing (κανόνας ή null)].
     *
     * @return array<string, array{price_kwh: float|null, fixed_charge: float|null, pricing: array<string, mixed>|null}>
     */
    public static function all(): array
    {
        $out = [
            'protergia_value_sure4_12m' => self::flat(0.177, 11.90, 'με έκπτωση συνέπειας· χωρίς: 0,247 €/kWh'),
            'protergia_value_balance_12m' => self::tiered(150, 0.139, 0.219, 10.90),
            'protergia_epag1_balance_12m' => self::tiered(250, 0.159, 0.219, 12.90),
            'protergia_epag2_power2' => self::tiered(1000, 0.1849, 0.2149, 14.90),
            'protergia_epag3_sure12m_2' => self::flat(0.1999, 0.0, 'με έκπτωση συνέπειας· χωρίς: 0,269 €/kWh'),
        ];

        $home = self::family('h');
        $biz  = self::family('b');

        foreach (array_keys(self::PACKAGES) as $size) {
            $out['protergia_pic_h_' . $size] = self::homePackage('protergia_pic_h_' . $size, $home, 'protergia_pic_h');
            $out['protergia_pic_b_' . $size] = self::package(
                'protergia_pic_b_' . $size,
                $biz,
                'protergia_pic_b',
                self::BIZ_UNIT,
                self::BIZ_EXCESS
            );
        }

        // Picasso 2.0 και Student: «ίδια χωρίς διαφοροποίηση» με το S 39,90
        // (Protergia 29/10, βλ. ProtergiaHomeSheets). Το Picasso 2.0 είναι το
        // ίδιο πρόγραμμα με το S 39,90 -- ίδια οικογένεια. Το Student μόνο
        // για φοιτητές: δική του οικογένεια, ώστε η πρόταση να μην το κρύβει.
        $out['protergia_picasso2']         = self::homePackage('protergia_pic_h_s1', $home, 'protergia_pic_h');
        $student                           = 'protergia_picasso2_student';
        $out['protergia_picasso2_student'] = self::homePackage('protergia_pic_h_s1', $home, $student);

        return $out;
    }

    /**
     * @return array{price_kwh: float, fixed_charge: float, pricing: array<string, mixed>|null}
     */
    private static function flat(float $price, float $fixed, string $note): array
    {
        return [
            'price_kwh'    => $price,
            'fixed_charge' => $fixed,
            'pricing'      => ['kind' => 'flat', 'note' => $note],
        ];
    }

    /**
     * @return array{price_kwh: null, fixed_charge: float, pricing: array<string, mixed>}
     */
    private static function tiered(int $limit, float $first, float $rest, float $fixed): array
    {
        return [
            // Μία τιμή εδώ θα την έπαιρνε ο χειροκίνητος υπολογισμός ως ενιαία.
            'price_kwh'    => null,
            'fixed_charge' => $fixed,
            'pricing'      => [
                'kind'  => 'tiered',
                'tiers' => [[$limit, $first], [null, $rest]],
                'note'  => $limit . ' kWh/30 ημέρες στην πρώτη τιμή',
            ],
        ];
    }

    /**
     * @param list<array{code: string, label: string, kwh: int, supply_month: float, price: float}> $family
     * @return array{price_kwh: null, fixed_charge: null, pricing: array<string, mixed>}
     */
    private static function homePackage(string $code, array $family, string $familyId): array
    {
        return self::package($code, $family, $familyId, self::HOME_UNIT, self::HOME_EXCESS);
    }

    /**
     * Τα εννιά πακέτα της κατηγορίας, μικρότερο πρώτο.
     *
     * @return list<array{code: string, label: string, kwh: int, supply_month: float, price: float}>
     */
    private static function family(string $category): array
    {
        $out = [];

        foreach (self::PACKAGES as $size => [$kwh, $homeSupply, $bizSupply, $homePrice, $bizPrice]) {
            $price  = $category === 'h' ? $homePrice : $bizPrice;
            $out[] = [
                'code'         => 'protergia_pic_' . $category . '_' . $size,
                'label'        => strtoupper($size[0]) . ' ' . number_format($price, 2, ',', ''),
                'kwh'          => $kwh,
                'supply_month' => $category === 'h' ? $homeSupply : $bizSupply,
                'price'        => $price,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{code: string, label: string, kwh: int, supply_month: float, price: float}> $family
     * @return array{price_kwh: null, fixed_charge: null, pricing: array<string, mixed>}
     */
    private static function package(string $code, array $family, string $familyId, float $unit, float $excess): array
    {
        $own = array_values(array_filter($family, static fn (array $p): bool => $p['code'] === $code))[0];

        return [
            'price_kwh'    => null,
            'fixed_charge' => null,
            'pricing'      => [
                'kind'         => 'package',
                'package_kwh'  => $own['kwh'],
                'supply_month' => $own['supply_month'],
                'excess_kwh'   => $excess,
                // (332) Εκκαθάριση με Πακέτο Αναφοράς: η οικογένεια, η
                // μοναδιαία τιμή για την υπολειπόμενη, και ποιο είναι αυτό.
                'unit_kwh'     => $unit,
                'family_id'    => $familyId,
                'code'         => $code,
                'family'       => $family,
            ],
        ];
    }
}
