<?php

/**
 * (319) Τα επαγγελματικά προγράμματα ρεύματος της Protergia, όπως τα δείχνει
 * σήμερα η ίδια (protergia.gr → Για την Επιχείρηση → Ρεύμα, 17/10/2026).
 *
 * Η λίστα της Protergia έχει 16 επαγγελματικά ρεύματος. Τα δύο υπήρχαν ήδη
 * με δικό τους έντυπο (`ProtergiaBizPlans`: 1 Value Simple 2.0, 1 Value
 * Seasonal) και το Picasso ως S/M/L (`ProtergiaPicassoPlans`). Εδώ είναι τα
 * υπόλοιπα 13 ρεύματος, και τα 2 επαγγελματικά φυσικού αερίου της ίδιας
 * λίστας (Value Gas Flex, Value Gas Standard).
 *
 * ## «1», «2», «3»
 *
 * Το νούμερο είναι μέρος του ονόματος στη Protergia και αντιστοιχεί στο
 * τιμολόγιο του ΔΕΔΔΗΕ (Γ21 / Γ22 / Γ23 -- η ίδια γράφει «Value SURE 2.0
 * (ΑΦΟΡΑ Γ23)» για το 3). Κρατιέται αυτούσιο στο όνομα: ο πωλητής ψάχνει με
 * το όνομα που βλέπει στο υλικό της Protergia.
 *
 * ## Έντυπο
 *
 * Δεν έχουν δικό τους φύλλο. Τα ρεύματος τυπώνουν το γενικό επαγγελματικό
 * `protergia_he_biz` -- και αυτό ΑΚΟΜΑ κι αν ο πελάτης καταχωρήθηκε κατά λάθος
 * ως ιδιώτης (ίδιος κανόνας με το (283): το τιμολόγιο αποφασίζει το έντυπο).
 * Τα αερίου τυπώνουν το γενικό `protergia_fa`, όπως κάθε αέριο Protergia χωρίς
 * δικό του φύλλο.
 *
 * ## Αποσυρμένο
 *
 * Το «Γ21 Value Sure 12 Μήνες 3.0» (`ProtergiaBizPlans::SURE_12`) δεν είναι πια
 * στη λίστα της Protergia· ο ιδιοκτήτης είπε να απενεργοποιηθεί (17/10/2026).
 * Δεν σβήνεται: οι παλιές αιτήσεις το κρατάνε και τυπώνουν το φύλλο τους.
 *
 * ## Τιμές
 *
 * ΟΧΙ εδώ. Αλλάζουν κάθε μήνα στη σελίδα της Protergia· γραμμένες στον κώδικα
 * θα ήταν λάθος από τον επόμενο μήνα. Ίδια απόφαση με το `ProtergiaCatalogue`.
 * Το χρώμα (price_type) μόνο όπου το λέει το όνομα ή ο πίνακας της Protergia:
 * Sure = σταθερό, Dynamic = δυναμικό, Special = ειδικό, Standard/Simple με
 * μηνιαία τιμή = κυμαινόμενο. Balance/Power έχουν κλιμακωτή τιμή και μένουν
 * χωρίς χρώμα (φαίνονται σε κάθε φίλτρο)· ο διαχειριστής το αλλάζει από τη
 * σελίδα παρόχων.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class ProtergiaBizCatalogue
{
    /** Το φύλλο που τυπώνουν όλα. */
    public const TEMPLATE = 'protergia_he_biz';

    /**
     * Με τη σειρά της λίστας της Protergia.
     *
     * @var array<string, array{label: string, energy: string, priceType: string, sort: int}>
     */
    private const PLANS = [
        'protergia_epag1_balance_12m' => [
            'label'     => 'Protergia Επαγγελματικό 1 — Value Balance 12M',
            'energy'    => 'power',
            'priceType' => '',
            'sort'      => 40,
        ],
        'protergia_epag1_standard2' => [
            'label'     => 'Protergia Επαγγελματικό 1 — Value Standard 2.0',
            'energy'    => 'power',
            'priceType' => 'variable',
            'sort'      => 41,
        ],
        'protergia_epag1_dynamic_one' => [
            'label'     => 'Protergia Επαγγελματικό 1 — Dynamic One',
            'energy'    => 'power',
            'priceType' => 'dynamic',
            'sort'      => 42,
        ],
        'protergia_epag1_special' => [
            'label'     => 'Protergia Επαγγελματικό 1 — Value Special',
            'energy'    => 'power',
            'priceType' => 'special',
            'sort'      => 43,
        ],
        'protergia_epag2_power2' => [
            'label'     => 'Protergia Επαγγελματικό 2 — Value Power 2.0',
            'energy'    => 'power',
            'priceType' => '',
            'sort'      => 44,
        ],
        'protergia_epag2_standard2' => [
            'label'     => 'Protergia Επαγγελματικό 2 — Value Standard 2.0',
            'energy'    => 'power',
            'priceType' => 'variable',
            'sort'      => 45,
        ],
        'protergia_epag2_simple2' => [
            'label'     => 'Protergia Επαγγελματικό 2 — Value Simple 2.0',
            'energy'    => 'power',
            'priceType' => 'variable',
            'sort'      => 46,
        ],
        'protergia_epag2_dynamic_one' => [
            'label'     => 'Protergia Επαγγελματικό 2 — Dynamic One',
            'energy'    => 'power',
            'priceType' => 'dynamic',
            'sort'      => 47,
        ],
        'protergia_epag2_special' => [
            'label'     => 'Protergia Επαγγελματικό 2 — Value Special',
            'energy'    => 'power',
            'priceType' => 'special',
            'sort'      => 48,
        ],
        'protergia_epag3_sure12m_2' => [
            'label'     => 'Protergia Επαγγελματικό 3 — Value Sure 12M 2.0',
            'energy'    => 'power',
            'priceType' => 'fixed',
            'sort'      => 49,
        ],
        'protergia_epag3_standard2' => [
            'label'     => 'Protergia Επαγγελματικό 3 — Value Standard 2.0',
            'energy'    => 'power',
            'priceType' => 'variable',
            'sort'      => 50,
        ],
        'protergia_epag3_simple2' => [
            'label'     => 'Protergia Επαγγελματικό 3 — Value Simple 2.0',
            'energy'    => 'power',
            'priceType' => 'variable',
            'sort'      => 51,
        ],
        'protergia_epag3_special' => [
            'label'     => 'Protergia Επαγγελματικό 3 — Value Special',
            'energy'    => 'power',
            'priceType' => 'special',
            'sort'      => 52,
        ],
        // Φυσικό αέριο, ίδια λίστα. Κυμαινόμενα, όπως τα οικιακά Gas Flex/Standard.
        'protergia_epag_gas_flex' => [
            'label'     => 'Protergia Επαγγελματικό — Value Gas Flex',
            'energy'    => 'gas',
            'priceType' => 'variable',
            'sort'      => 53,
        ],
        'protergia_epag_gas_standard' => [
            'label'     => 'Protergia Επαγγελματικό — Value Gas Standard',
            'energy'    => 'gas',
            'priceType' => 'variable',
            'sort'      => 54,
        ],
    ];

    /** Όσα επαγγελματικά δεν είναι πια στη λίστα της Protergia. */
    public const RETIRED = [ProtergiaBizPlans::SURE_12];

    private function __construct()
    {
    }

    /** @return array<string, array{label: string, energy: string, priceType: string, sort: int}> */
    public static function all(): array
    {
        return self::PLANS;
    }

    public static function exists(string $code): bool
    {
        return isset(self::PLANS[$code]);
    }

    /** Ένα από τα επαγγελματικά ΡΕΥΜΑΤΟΣ του καταλόγου (→ `TEMPLATE`). */
    public static function isPower(string $code): bool
    {
        return (self::PLANS[$code]['energy'] ?? '') === 'power';
    }

    public static function isRetired(string $code): bool
    {
        return in_array($code, self::RETIRED, true);
    }
}
