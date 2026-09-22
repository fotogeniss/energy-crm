<?php

/**
 * Τα τρία επαγγελματικά τιμολόγια Protergia και το έντυπο που τυπώνει το καθένα.
 *
 * Ιδια λογική με το `ProtergiaHomePlans`, για τον ίδιο λόγο: η Protergia δίνει
 * ένα έντυπο ανά τιμολόγιο, με τον πίνακα τιμών της σελ. 2 ήδη τυπωμένο από
 * την ίδια. Το «ποιο πρόγραμμα διάλεξε ο πελάτης» είναι άρα το «ποιο έντυπο
 * τυπώνεται» -- όχι κάτι που γράφει το CRM πάνω σε ένα κοινό φύλλο.
 *
 * Μέχρι σήμερα κάθε επαγγελματική σύμβαση ρεύματος Protergia τύπωνε το ενιαίο
 * `protergia_he_biz` (Picasso 2.0). Αυτό μένει ως η επιστροφή για ό,τι δεν
 * είναι ένα από τα τρία: μια σύμβαση πρέπει να τυπώνει το έντυπο πάνω στο
 * οποίο πουλήθηκε, όχι το σημερινό.
 *
 * ## Γιατί ξεχωριστή κλάση και όχι τρεις γραμμές στο ProtergiaHomePlans
 *
 * Τα οικιακά μπαίνουν με `category = home` και τα τεστ τους απαριθμούν
 * ονομαστικά τέσσερα. Αν τα επαγγελματικά ζούσαν εκεί, κάθε καταναλωτής του
 * `ProtergiaHomePlans::all()` θα έπρεπε να ξέρει ότι «οικιακά» σημαίνει πλέον
 * «οικιακά εκτός από τρία» -- και το seed θα έγραφε επαγγελματικά τιμολόγια
 * στην κατηγορία του οικιακού dropdown.
 *
 * ## Χρώμα
 *
 * Ο συνεργάτης (22/09/2026): «Σταθερά (μπλε τιμολόγια): Γ21 SURE. Κυμαινόμενα
 * (κίτρινα τιμολόγια): SEASONAL, SIMPLE». Το ίδιο το έντυπο του Seasonal
 * γράφει «Μεικτή» τιμολόγηση (σταθερή το καλοκαίρι, κυμαινόμενη τον χειμώνα)·
 * εδώ ακολουθείται ο συνεργάτης, γιατί το χρώμα είναι το φίλτρο με το οποίο ο
 * πωλητής ψάχνει το πρόγραμμα στη φόρμα, και «κίτρινο» είναι αυτό που θα πατήσει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class ProtergiaBizPlans
{
    public const SURE_12  = 'protergia_epag_sure12';
    public const SIMPLE_2 = 'protergia_epag_simple2';
    public const SEASONAL = 'protergia_epag_seasonal';

    /**
     * plan code => [ετικέτα dropdown, χρώμα (price_type), πάγιο €/μήνα,
     *               χρέωση προμήθειας €/kWh όπου είναι σταθερή].
     *
     * Τα νούμερα όπως τα τυπώνει η σελ. 2 κάθε εντύπου:
     * - Sure 12 Μήνες 3.0: 13,90 €/μήνα, 269 €/MWh (169,90 με Έκπτωση Συνέπειας
     *   -- καταγράφεται η τιμή χωρίς έκπτωση, όπως και στα οικιακά).
     * - Simple 2.0: 5 €/μήνα, 1,21 × ΤΕΑ Αναφοράς + 45 €/MWh -- καμία σταθερή τιμή.
     * - Seasonal (2026): πάγιο 11,90 €/μήνα ΜΟΝΟ Μάιο-Οκτώβριο και 0 τον χειμώνα,
     *   144,90 €/MWh το καλοκαίρι και τύπος τον χειμώνα. Κανένα από τα δύο δεν
     *   είναι «το» πάγιο ή «η» τιμή· ένα νούμερο εδώ θα φαινόταν στο dropdown
     *   σαν να ισχύει όλο τον χρόνο. Γι' αυτό null -- ίδιος κανόνας με τα
     *   αμέτρητα του `VoltonPlans`.
     *
     * Ο κωδικός είναι ΚΑΙ το όνομα του εντύπου (`assets/forms/<code>.json`).
     *
     * @var array<string, array{
     *   label: string, priceType: string, fixedCharge: float|null, priceKwh: float|null
     * }>
     */
    private const PLANS = [
        self::SURE_12 => [
            'label'       => 'Protergia Επαγγελματικό Γ21 — Value Sure 12 Μήνες 3.0',
            'priceType'   => 'fixed',
            'fixedCharge' => 13.90,
            'priceKwh'    => 0.269,
        ],
        self::SIMPLE_2 => [
            'label'       => 'Protergia Επαγγελματικό — Value Simple 2.0',
            'priceType'   => 'variable',
            'fixedCharge' => 5.00,
            'priceKwh'    => null,
        ],
        self::SEASONAL => [
            'label'       => 'Protergia Επαγγελματικό — Value Seasonal (2026)',
            'priceType'   => 'variable',
            'fixedCharge' => null,
            'priceKwh'    => null,
        ],
    ];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::PLANS);
    }

    public static function exists(string $plan): bool
    {
        return isset(self::PLANS[$plan]);
    }

    /**
     * Το έντυπο ενός προγράμματος, ή '' όταν δεν είναι δικό μας.
     *
     * '' και όχι κάποιο default: σύμβαση σε τιμολόγιο χωρίς φύλλο πέφτει στην
     * επιλογή του καλούντος, όχι σε φύλλο που γράφει άλλο τιμολόγιο.
     */
    public static function templateKey(string $plan): string
    {
        return self::exists($plan) ? $plan : '';
    }

    /**
     * Κάθε πρόγραμμα ως γραμμή `programs`, έτοιμη για seed.
     *
     * @return array<string, array{
     *   label: string, priceType: string, fixedCharge: float|null, priceKwh: float|null
     * }>
     */
    public static function all(): array
    {
        return self::PLANS;
    }
}
