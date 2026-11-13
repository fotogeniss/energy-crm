<?php

/**
 * (380) Τα προγράμματα ρεύματος της ΔΕΗ και τα έντυπα που τυπώνει το καθένα.
 *
 * Πηγή: τα επίσημα PDF του dei.gr, όπως τα κατέβασε ο ιδιοκτήτης στις
 * 13/11/2026 (`deploy\dei`, `dei-download.ps1`). Το dei.gr δεν διαβάζεται
 * αυτόματα από εδώ· κάθε νέα έκδοση έρχεται με τον ίδιο τρόπο.
 *
 * ## Τι τυπώνεται
 *
 * 1. Η «Αίτηση Προσφοράς Προμήθειας» (μία σελίδα, έκδοση 01.04.2026). Η ΔΕΗ
 *    έχει τέσσερις: έως 25 kVA, μεγάλου πελάτη, και οι δύο ξανά για προϊόν
 *    δυναμικής τιμολόγησης. Διαλέγει η ισχύς παροχής και το πρόγραμμα.
 * 2. Οι Ειδικοί Όροι του προγράμματος, με τον τιμοκατάλογό του. Υπογραφή
 *    μόνο όπου τη ζητά η ίδια η ΔΕΗ («ΠΕΛΑΤΗΣ (ΥΠΟΓΡΑΦΗ)»).
 * 3. Οι Γενικοί Όροι Σύμβασης (μικρού ή μεγάλου πελάτη), χωρίς συμπλήρωση.
 * 4. Στα δυναμικά, η Προσυμβατική Ενημέρωση -- την αναφέρει η ίδια η αίτηση.
 *
 * ## Εκδόσεις
 *
 * Η ΔΕΗ βγάζει νέα έκδοση σχεδόν κάθε μήνα («myHome Enter 11.26», «12.26»).
 * Ο κωδικός του προγράμματος μένει ο ίδιος· αλλάζουν οι σελίδες των
 * Ειδικών Όρων, το `tariff` (τυπώνεται στο «Τιμολόγιο:» της αίτησης) και η
 * τιμή. Οι παλιές αιτήσεις κρατούν το πρόγραμμα, όχι την έκδοση.
 *
 * Καθαρή κλάση, χωρίς WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class DeiCatalogue
{
    /** Το slug του παρόχου στον πίνακα providers. */
    public const SLUG = 'dei';

    /** Ημερομηνία των εντύπων από τα οποία πάρθηκαν τιμές και σελίδες. */
    public const AS_OF = '2026-11-13';

    /** Πάνω από αυτή την ισχύ παροχής η ΔΕΗ θέλει την αίτηση «Μεγάλου Πελάτη». */
    public const SMALL_KVA = 25.0;

    public const APP_SMALL = 'dei_aitisi_mikrou';

    public const APP_LARGE = 'dei_aitisi_megalou';

    public const APP_DYN_SMALL = 'dei_aitisi_dyn_mikrou';

    public const APP_DYN_LARGE = 'dei_aitisi_dyn_megalou';

    public const TERMS_SMALL = 'dei_gos_mikrou';

    public const TERMS_LARGE = 'dei_gos_megalou';

    public const TERMS_DYN_LARGE = 'dei_gos_dyn_megalou';

    public const PRECONTRACT_HOME = 'dei_prosymv_oikiakou';

    public const PRECONTRACT_BUSINESS = 'dei_prosymv_epaggelmatikou';

    /**
     * Κωδικός => πρόγραμμα.
     *
     * `tariff`: όπως γράφεται στο «Τιμολόγιο:» της αίτησης, με την έκδοση
     * όπου την έχει το έντυπο. `note`: προϋπόθεση της ΔΕΗ, φαίνεται κάτω από
     * το πρόγραμμα στη «Νέα αίτηση».
     *
     * @var array<string, array{
     *     label: string, tariff: string, category: string, priceType: string,
     *     sort: int, dynamic: bool, note: string
     * }>
     */
    private const PLANS = [
        'dei_myhome_enter' => [
            'label' => 'ΔΕΗ myHome Enter', 'tariff' => 'ΔΕΗ myHome Enter 11.26', 'category' => 'home',
            'priceType' => 'fixed', 'sort' => 1, 'dynamic' => false,
            'note' => 'Όχι σε πελάτη με ενεργό ενεργειακό συμψηφισμό.',
        ],
        'dei_myhome_entertwo' => [
            'label' => 'ΔΕΗ myHome EnterTwo', 'tariff' => 'ΔΕΗ myHome EnterTwo 11.26', 'category' => 'home',
            'priceType' => 'fixed', 'sort' => 2, 'dynamic' => false,
            'note' => 'Μόνο για παροχή με διζωνικό (νυχτερινό) μετρητή.',
        ],
        'dei_myhome_online' => [
            'label' => 'ΔΕΗ myHome Online', 'tariff' => 'ΔΕΗ myHome Online 11.26', 'category' => 'home',
            'priceType' => 'fixed', 'sort' => 3, 'dynamic' => false,
            'note' => 'Λογαριασμός μόνο ηλεκτρονικά στο myΔΕΗ και πάγια εντολή σε κάρτα.',
        ],
        'dei_myhome_maxima' => [
            'label' => 'ΔΕΗ myHome Maxima', 'tariff' => 'ΔΕΗ myHome Maxima 11.26', 'category' => 'home',
            'priceType' => 'fixed', 'sort' => 4, 'dynamic' => false,
            'note' => 'Όχι σε πελάτη με ενεργό ενεργειακό συμψηφισμό.',
        ],
        'dei_myhome_plan' => [
            'label' => 'ΔΕΗ myHome Plan', 'tariff' => 'ΔΕΗ myHome Plan 06.26', 'category' => 'home',
            'priceType' => 'fixed', 'sort' => 5, 'dynamic' => false,
            'note' => 'Έως 25 kVA, όχι με τηλεμετρούμενο μετρητή ή ενεργειακό συμψηφισμό.',
        ],
        'dei_myhome_4all' => [
            'label' => 'ΔΕΗ myHome 4All', 'tariff' => 'ΔΕΗ myHome 4All', 'category' => 'home',
            'priceType' => 'variable', 'sort' => 6, 'dynamic' => false, 'note' => '',
        ],
        'dei_myhome_4all_onerate' => [
            'label' => 'ΔΕΗ myHome 4All OneRate', 'tariff' => 'ΔΕΗ myHome 4All OneRate', 'category' => 'home',
            'priceType' => 'variable', 'sort' => 7, 'dynamic' => false, 'note' => '',
        ],
        'dei_myhome_4students' => [
            'label' => 'ΔΕΗ myHome 4Students', 'tariff' => 'ΔΕΗ myHome4Students 11.26', 'category' => 'home',
            'priceType' => 'variable', 'sort' => 8, 'dynamic' => false,
            'note' => 'Μόνο για φοιτητές, με ενεργή ακαδημαϊκή ταυτότητα.',
        ],
        'dei_myhome_dynamic' => [
            'label' => 'ΔΕΗ myHome Dynamic', 'tariff' => 'ΔΕΗ myHome Dynamic', 'category' => 'home',
            'priceType' => 'dynamic', 'sort' => 9, 'dynamic' => true,
            'note' => 'Μόνο με τηλεμετρούμενο μετρητή.',
        ],
        'dei_g1' => [
            'label' => 'ΔΕΗ Γ1 / Γ1Ν Οικιακό', 'tariff' => 'Γ1 / Γ1Ν Οικιακό', 'category' => 'home',
            'priceType' => 'special', 'sort' => 10, 'dynamic' => false, 'note' => '',
        ],
        'dei_mybusiness_enter' => [
            'label' => 'ΔΕΗ myBusiness Enter', 'tariff' => 'ΔΕΗ myBusiness Enter 11.26', 'category' => 'business',
            'priceType' => 'fixed', 'sort' => 20, 'dynamic' => false,
            'note' => 'Για εγκατεστημένη ισχύ έως 35 kVA.',
        ],
        'dei_mybusiness_4all' => [
            'label' => 'ΔΕΗ myBusiness 4All', 'tariff' => 'ΔΕΗ myBusiness 4All', 'category' => 'business',
            'priceType' => 'variable', 'sort' => 21, 'dynamic' => false,
            'note' => 'Για ετήσια κατανάλωση έως 10 MWh.',
        ],
        'dei_mybusiness_dynamic' => [
            'label' => 'ΔΕΗ myBusiness Dynamic', 'tariff' => 'ΔΕΗ myBusiness Dynamic', 'category' => 'business',
            'priceType' => 'dynamic', 'sort' => 22, 'dynamic' => true,
            'note' => 'Μόνο με τηλεμετρούμενο μετρητή.',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string, array{
     *     label: string, tariff: string, category: string, priceType: string,
     *     sort: int, dynamic: bool, note: string
     * }>
     */
    public static function all(): array
    {
        return self::PLANS;
    }

    public static function exists(string $code): bool
    {
        return isset(self::PLANS[$code]);
    }

    public static function isDynamic(string $code): bool
    {
        return (self::PLANS[$code]['dynamic'] ?? false) === true;
    }

    /**
     * Το «Τιμολόγιο:» της αίτησης. Για Γ1 ο κωδικός τιμολογίου του πελάτη
     * (Γ1 ή Γ1Ν) λέει ποιο από τα δύο. '' για ό,τι δεν είναι του καταλόγου.
     */
    public static function tariff(string $code, bool $night = false): string
    {
        if ($code === 'dei_g1') {
            return $night ? 'Γ1Ν Οικιακό' : 'Γ1 Οικιακό';
        }

        return self::PLANS[$code]['tariff'] ?? '';
    }

    /**
     * Οι προϋποθέσεις της ΔΕΗ, κωδικός => κείμενο, μόνο όπου υπάρχει.
     *
     * @return array<string, string>
     */
    public static function notes(): array
    {
        $out = [];

        foreach (self::PLANS as $code => $plan) {
            if ($plan['note'] !== '') {
                $out[$code] = $plan['note'];
            }
        }

        return $out;
    }

    /** Οι Ειδικοί Όροι του προγράμματος, ή '' για ό,τι δεν είναι του καταλόγου. */
    public static function termsSheet(string $code): string
    {
        return self::exists($code) ? 'dei_eo_' . substr($code, 4) : '';
    }

    /**
     * Η αίτηση που θέλει η ΔΕΗ για αυτό το πρόγραμμα και αυτή την ισχύ.
     *
     * Χωρίς δηλωμένη ισχύ (0): η αίτηση έως 25 kVA, που καλύπτει όλα τα
     * οικιακά και τις περισσότερες μικρές επιχειρήσεις.
     */
    public static function application(string $code, float $kva): string
    {
        $large = $kva > self::SMALL_KVA;

        if (self::isDynamic($code)) {
            return $large ? self::APP_DYN_LARGE : self::APP_DYN_SMALL;
        }

        return $large ? self::APP_LARGE : self::APP_SMALL;
    }

    /**
     * Όλα τα έντυπα της αίτησης, με τη σειρά που τυπώνονται.
     *
     * @return list<string>
     */
    public static function paperwork(string $code, float $kva): array
    {
        $large = $kva > self::SMALL_KVA;
        $keys  = [self::application($code, $kva)];

        if (self::termsSheet($code) !== '') {
            $keys[] = self::termsSheet($code);
        }

        if (! $large) {
            $keys[] = self::TERMS_SMALL;
        } else {
            $keys[] = self::isDynamic($code) ? self::TERMS_DYN_LARGE : self::TERMS_LARGE;
        }

        if (self::isDynamic($code)) {
            $keys[] = (self::PLANS[$code]['category'] ?? '') === 'business'
                ? self::PRECONTRACT_BUSINESS
                : self::PRECONTRACT_HOME;
        }

        return $keys;
    }

    /**
     * Κάθε έντυπο της ΔΕΗ που πρέπει να υπάρχει στον δίσκο.
     *
     * @return list<string>
     */
    public static function templates(): array
    {
        $keys = [
            self::APP_SMALL, self::APP_LARGE, self::APP_DYN_SMALL, self::APP_DYN_LARGE,
            self::TERMS_SMALL, self::TERMS_LARGE, self::TERMS_DYN_LARGE,
            self::PRECONTRACT_HOME, self::PRECONTRACT_BUSINESS,
        ];

        foreach (array_keys(self::PLANS) as $code) {
            $keys[] = self::termsSheet($code);
        }

        return $keys;
    }

    /**
     * Ισχύς παροχής από ό,τι πληκτρολόγησε ο συνεργάτης («8», «8 kVA»,
     * «17,3»). 0 όταν δεν διαβάζεται.
     */
    public static function kva(string $typed): float
    {
        if (preg_match('/\d+(?:[.,]\d+)?/', $typed, $m) !== 1) {
            return 0.0;
        }

        return (float) str_replace(',', '.', $m[0]);
    }
}
