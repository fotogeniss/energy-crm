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

    /** Ίδια πακέτα kWh σε οικιακά και επαγγελματικά, άλλες χρεώσεις. */
    private const PACKAGES = [
        's1' => [1325, 21.50, 21.01],
        's2' => [1875, 29.71, 33.81],
        's3' => [2700, 42.03, 48.51],
        'm1' => [3550, 57.27, 62.12],
        'm2' => [4600, 73.86, 82.92],
        'm3' => [6000, 97.00, 107.32],
        'l1' => [11000, 173.22, 187.32],
        'l2' => [15300, 231.27, 260.13],
        'l3' => [20200, 303.46, 340.53],
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

        foreach (self::PACKAGES as $size => [$kwh, $home, $biz]) {
            $out['protergia_pic_h_' . $size] = self::package($kwh, $home, self::HOME_EXCESS);
            $out['protergia_pic_b_' . $size] = self::package($kwh, $biz, self::BIZ_EXCESS);
        }

        // Picasso 2.0 και Student: «ίδια χωρίς διαφοροποίηση» με το S 39,90
        // (Protergia 29/10, βλ. ProtergiaHomeSheets).
        $out['protergia_picasso2']         = $out['protergia_pic_h_s1'];
        $out['protergia_picasso2_student'] = $out['protergia_pic_h_s1'];

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
     * @return array{price_kwh: null, fixed_charge: null, pricing: array<string, mixed>}
     */
    private static function package(int $kwh, float $supplyMonth, float $excess): array
    {
        return [
            'price_kwh'    => null,
            'fixed_charge' => null,
            'pricing'      => [
                'kind'         => 'package',
                'package_kwh'  => $kwh,
                'supply_month' => $supplyMonth,
                'excess_kwh'   => $excess,
            ],
        ];
    }
}
