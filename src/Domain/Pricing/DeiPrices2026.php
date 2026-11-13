<?php

/**
 * (380) Οι τιμές της ΔΕΗ από τα έντυπα 11.26 (dei.gr, 13/11/2026).
 *
 * Χωρίς ΦΠΑ, όπως τις γράφει η ΔΕΗ. Πηγές (σελ. 1 του φυλλαδίου κάθε
 * προγράμματος, «ΧΡΕΩΣΕΙΣ ΠΡΟΜΗΘΕΙΑΣ»):
 * - myHome Enter 11.26: 0,17900 €/kWh, πάγιο 7,5.
 * - myHome EnterTwo 11.26: 0,17900 κανονική / 0,13900 μειωμένη, πάγιο 9,0.
 * - myHome Online 11.26: 0,17500 / 0,16500, «0,13000 μετά την εφαρμογή της
 *   προωθητικής έκπτωσης» και στις δύο ζώνες, πάγιο 5,0.
 * - myHome Maxima 11.26: 0,17500 οι πρώτες 600 kWh/μήνα, 0,16300 οι
 *   υπόλοιπες, πάγιο 13,50.
 * - myHome Plan 06.26 (τελευταίο στο site): 0,14500, πάγιο 7,5, και 60 €
 *   τον μήνα έναντι.
 * - myHome 4All, Τελική Τιμή Νοεμβρίου 2026: 0,31339 έως 500 kWh, 0,36939
 *   πάνω, 0,28739 μειωμένη, πάγιο 5,0.
 * - myHome 4All OneRate: βασική 0,14000 / 0,11900, πάγιο 7,0· ο μηχανισμός
 *   ανακοινώνεται μετά τον μήνα κατανάλωσης.
 * - myHome4Students 11.26: 0,16700 έως 150 kWh, 0,19900 πάνω (+ διακύμανση
 *   μόνο στο δεύτερο κλιμάκιο), πάγιο 4,0.
 * - Γ1/Γ1Ν, Τελική Τιμή Νοεμβρίου 2026 με εμπρόθεσμη εξόφληση και ενεργό
 *   myΔΕΗ: 0,16901 έως 200 kWh, 0,28444 πάνω, 0,16713 μειωμένη, πάγιο 5,0.
 * - myBusiness Enter 11.26: 0,19500, πάγιο 9,0.
 * - myBusiness 4All, Final Supply Price November 2026: 0,32539, πάγιο 5,0.
 * - Τα δυναμικά (1,19 × ΤΕΑ ώρας + 0,044 / 0,054) μένουν χωρίς τιμή: αλλάζει
 *   κάθε ώρα.
 *
 * Τα κυμαινόμενα παλιώνουν σε 45 ημέρες (`ProgramPricing::isStale`), όπως
 * σε όλους τους παρόχους. Ίδια μορφή με το `ProtergiaPrices2026`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Pricing;

use EnergyCRM\Domain\Forms\DeiCatalogue;

final class DeiPrices2026
{
    public const AS_OF = DeiCatalogue::AS_OF;

    private function __construct()
    {
    }

    /**
     * @return array<string, array{price_kwh: float|null, fixed_charge: float|null, pricing: array<string, mixed>}>
     */
    public static function all(): array
    {
        return [
            'dei_myhome_enter'        => self::flat(0.179, 7.5, 'έντυπο 11.26'),
            'dei_myhome_entertwo'     => self::flat(0.179, 9.0, 'έντυπο 11.26· μειωμένη (νύχτα) 0,139 €/kWh'),
            'dei_myhome_online'       => self::flat(
                0.130,
                5.0,
                'με την προωθητική έκπτωση· χωρίς: 0,175 / 0,165 €/kWh'
            ),
            'dei_myhome_maxima'       => self::tiered(600, 0.175, 0.163, 13.5, 'έντυπο 11.26'),
            'dei_myhome_plan'         => self::flat(0.145, 7.5, 'έντυπο 06.26· 60 € τον μήνα έναντι, με εκκαθάριση'),
            'dei_myhome_4all'         => self::tiered(
                500,
                0.31339,
                0.36939,
                5.0,
                'τελική τιμή Νοεμβρίου 2026· μειωμένη 0,28739 €/kWh'
            ),
            'dei_myhome_4all_onerate' => self::flat(
                0.140,
                7.0,
                'βασική τιμή· η διακύμανση ανακοινώνεται μετά τον μήνα'
            ),
            'dei_myhome_4students'    => self::tiered(
                150,
                0.167,
                0.199,
                4.0,
                'έντυπο 11.26· πάνω από 150 kWh και διακύμανση'
            ),
            'dei_g1'                  => self::tiered(
                200,
                0.16901,
                0.28444,
                5.0,
                'τελική τιμή Νοεμβρίου 2026, εμπρόθεσμη εξόφληση με myΔΕΗ· μειωμένη 0,16713 €/kWh'
            ),
            'dei_mybusiness_enter'    => self::flat(0.195, 9.0, 'έντυπο 11.26'),
            'dei_mybusiness_4all'     => self::flat(0.32539, 5.0, 'τελική τιμή Νοεμβρίου 2026'),
        ];
    }

    /** @return array{price_kwh: float, fixed_charge: float, pricing: array<string, mixed>} */
    private static function flat(float $price, float $fixed, string $note): array
    {
        return [
            'price_kwh'    => $price,
            'fixed_charge' => $fixed,
            'pricing'      => ['kind' => 'flat', 'note' => $note],
        ];
    }

    /** @return array{price_kwh: null, fixed_charge: float, pricing: array<string, mixed>} */
    private static function tiered(int $limit, float $first, float $rest, float $fixed, string $note): array
    {
        return [
            // Μία τιμή εδώ θα την έπαιρνε ο χειροκίνητος υπολογισμός ως ενιαία.
            'price_kwh'    => null,
            'fixed_charge' => $fixed,
            'pricing'      => [
                'kind'  => 'tiered',
                'tiers' => [[$limit, $first], [null, $rest]],
                'note'  => $limit . ' kWh/30 ημέρες στην πρώτη τιμή· ' . $note,
            ],
        ];
    }
}
