<?php

/**
 * Ο κατάλογος προγραμμάτων της Volton — ρεύμα και αέριο, οικιακά,
 * επαγγελματικά και κοινόχρηστα.
 *
 * Η Volton έμπαινε στο CRM με ένα και μόνο πρόγραμμα: το γενικό «Σταθερό
 * Οικιακό» που μοιράζει η `ECRM_Providers::seed()` σε κάθε πάροχο ρεύματος
 * χωρίς δικό του κατάλογο. Ο πωλητής άνοιγε το dropdown και είχε μία επιλογή
 * που δεν αντιστοιχούσε σε κανένα πραγματικό τιμολόγιο, ενώ η Volton πουλάει
 * είκοσι τρία. Χειρότερα: το ίδιο μονοπάτι δεν φτιάχνει ΠΟΤΕ πρόγραμμα αερίου
 * (κοιτάζει μόνο `power`), οπότε ένας πάροχος με `energy_types = 'power,gas'`
 * έμενε με άδειο dropdown στη μισή του δουλειά.
 *
 * ## Γιατί ΔΕΝ αντιγράφει το ProtergiaHomePlans
 *
 * Το `ProtergiaHomePlans` δένει κάθε πρόγραμμα σε **δικό του έντυπο**
 * (`templateKey()`), επειδή η Protergia δίνει τέσσερα ξεχωριστά έντυπα, ένα
 * ανά τιμολόγιο. Η Volton δίνει **δύο** συνολικά — `volton_he` για ρεύμα και
 * `volton_fa` για αέριο — και τα είκοσι τρία προγράμματα τυπώνονται πάνω τους.
 * Άρα εδώ δεν υπάρχει `templateKey()`: θα ήταν αντιστοίχιση σε έντυπα που δεν
 * υπάρχουν, και το πρώτο `assertFileExists` θα το έδειχνε. Το `code` μένει
 * παρ' όλα αυτά, για τον λόγο που το θέλει και το σχήμα: σταθερό αναγνωριστικό
 * ώστε μια μετονομασία στο wp-admin να μη σπάει τίποτα.
 *
 * ## Από πού βγήκαν οι τιμές
 *
 * Από τις σελίδες του volton.gr, μία-μία, 04/09/2026 — όχι από συγκεντρωτικό
 * πίνακα ούτε από εντύπωση. Οι τιμές €/kWh είναι οι **τιμές με έκπτωση
 * συνέπειας**, γιατί αυτές διαφημίζονται και αυτές συζητά ο πωλητής στο
 * τηλέφωνο. Τα κυμαινόμενα και τα ειδικά τιμολογούνται με τύπο πάνω στον ΜΤΑΜ
 * (ρεύμα) ή στο TTF (αέριο) και αλλάζουν κάθε μήνα, οπότε **δεν έχουν σταθερή
 * τιμή kWh να καταγραφεί** — `priceKwh` `null`, ακριβώς όπως τα κυμαινόμενα
 * της Protergia. Ένας φτιαχτός αριθμός εκεί δεν θα τυπωνόταν πουθενά, θα
 * φαινόταν όμως στο dropdown σαν να είναι η τιμή που πληρώνει ο πελάτης.
 *
 * Τρία πάγια έμειναν `null` επειδή **δεν μετρήθηκαν**, όχι επειδή είναι μηδέν:
 * `yellow simple` Γ22/Γ23 και `green ειδικό` Γ22/Γ23 δεν διαβάστηκαν από τις
 * δικές τους σελίδες (μόνο το Γ21 κάθε οικογένειας διαβάστηκε). Το `stay & win`
 * business επιβεβαιώθηκε και στα τρία τιμολόγια με ίδιο πάγιο 6,90 €, αλλά αυτό
 * είναι μέτρηση για εκείνο, όχι κανόνας για τα υπόλοιπα. Κενό πάγιο στο
 * dropdown είναι τίμιο· λάθος πάγιο είναι τιμή που ειπώθηκε λάθος στο τηλέφωνο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class VoltonPlans
{
    /**
     * code => [ετικέτα dropdown, ρεύμα ή αέριο, οικιακό/επαγγελματικό/
     *          κοινόχρηστο, ο τύπος τιμολόγησης όπως τον ονομάζει το σχήμα,
     *          μηνιαίο πάγιο σε €, χρέωση προμήθειας σε €/kWh όπου υπάρχει
     *          σταθερή].
     *
     * `category` και `priceType` παίρνουν μόνο τιμές που δέχεται ο πίνακας
     * `programs` (`home|business|communal`, `fixed|special|variable|dynamic`) —
     * το `price_type` είναι αυτό που η φόρμα δείχνει ως «ΧΡΩΜΑ».
     *
     * @var array<string, array{
     *   label: string, energyType: string, category: string, priceType: string,
     *   fixedCharge: float|null, priceKwh: float|null
     * }>
     */
    private const PLANS = [
        // --- Οικιακά, ρεύμα ------------------------------------------------
        'volton_blue_flat_18m' => [
            'label'       => 'Volton Blue Flat 18M',
            'energyType'  => 'power',
            'category'    => 'home',
            'priceType'   => 'fixed',
            'fixedCharge' => 11.90,
            'priceKwh'    => 0.135,
        ],
        'volton_blue_flat' => [
            'label'       => 'Volton Blue Flat',
            'energyType'  => 'power',
            'category'    => 'home',
            'priceType'   => 'fixed',
            'fixedCharge' => 14.90,
            'priceKwh'    => 0.145,
        ],
        'volton_blue_student' => [
            'label'       => 'Volton Blue Student',
            'energyType'  => 'power',
            'category'    => 'home',
            'priceType'   => 'fixed',
            'fixedCharge' => 5.00,
            'priceKwh'    => 0.129,
        ],
        // Πακέτο κατανάλωσης, όχι πάγιο + €/kWh: 29/58/87/116 € τον μήνα για
        // 150/300/450/600 kWh, με υπέρβαση 0,195 €/kWh. Μπαίνει ως ΕΝΑ
        // πρόγραμμα κατόπιν ρητής απόφασης του ιδιοκτήτη (04/09/2026) αντί για
        // τέσσερα. Το 29,00 είναι το «από» — η κλίμακα δεν χωρά στο σχήμα, και
        // δεν επινοείται στήλη για να χωρέσει.
        'volton_blue_smart' => [
            'label'       => 'Volton Blue Smart (πακέτο από 29 €)',
            'energyType'  => 'power',
            'category'    => 'home',
            'priceType'   => 'fixed',
            'fixedCharge' => 29.00,
            'priceKwh'    => null,
        ],
        'volton_stay_win' => [
            'label'       => 'Volton Stay & Win',
            'energyType'  => 'power',
            'category'    => 'home',
            'priceType'   => 'variable',
            'fixedCharge' => 6.90,
            'priceKwh'    => null,
        ],
        'volton_yellow_zero' => [
            'label'       => 'Volton Yellow Zero',
            'energyType'  => 'power',
            'category'    => 'home',
            'priceType'   => 'variable',
            'fixedCharge' => 0.00,
            'priceKwh'    => null,
        ],
        'volton_yellow_simple' => [
            'label'       => 'Volton Yellow Simple',
            'energyType'  => 'power',
            'category'    => 'home',
            'priceType'   => 'variable',
            'fixedCharge' => 4.90,
            'priceKwh'    => null,
        ],
        'volton_green_eidiko' => [
            'label'       => 'Volton Green Ειδικό',
            'energyType'  => 'power',
            'category'    => 'home',
            'priceType'   => 'special',
            'fixedCharge' => 4.90,
            'priceKwh'    => null,
        ],

        // --- Οικιακό, αέριο ------------------------------------------------
        'volton_gas_stay_win' => [
            'label'       => 'Volton Gas Stay & Win',
            'energyType'  => 'gas',
            'category'    => 'home',
            'priceType'   => 'variable',
            'fixedCharge' => 6.90,
            'priceKwh'    => null,
        ],

        // --- Κοινόχρηστο, αέριο --------------------------------------------
        // Η μόνη γραμμή του καταλόγου με `communal`: το ίδιο πρόγραμμα για
        // κοινόχρηστες εγκαταστάσεις πολυκατοικίας, με δικό του πάγιο 8,90 €.
        'volton_gas_stay_win_central' => [
            'label'       => 'Volton Gas Stay & Win Central (κοινόχρηστο)',
            'energyType'  => 'gas',
            'category'    => 'communal',
            'priceType'   => 'variable',
            'fixedCharge' => 8.90,
            'priceKwh'    => null,
        ],

        // --- Επαγγελματικά, ρεύμα ------------------------------------------
        // Τα «21/22/23» της Volton είναι τα τιμολόγια Γ21/Γ22/Γ23 που ήδη
        // επιλέγει η φόρμα, γι' αυτό μπαίνουν στην ετικέτα ως Γ21/Γ22/Γ23 και
        // όχι ως γυμνοί αριθμοί: ο πωλητής διαλέγει τιμολόγιο και μετά
        // πρόγραμμα, και τα δύο πρέπει να διαβάζονται σαν το ίδιο πράγμα.
        'volton_blue_flat_18m_g21' => [
            'label'       => 'Volton Blue Flat 18M Business Γ21',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'fixed',
            'fixedCharge' => 11.90,
            'priceKwh'    => 0.155,
        ],
        'volton_blue_flat_g21' => [
            'label'       => 'Volton Blue Flat Business Γ21',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'fixed',
            'fixedCharge' => 14.90,
            'priceKwh'    => 0.165,
        ],
        'volton_yellow_zero_g21' => [
            'label'       => 'Volton Yellow Zero Business Γ21',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'variable',
            'fixedCharge' => 0.00,
            'priceKwh'    => null,
        ],
        'volton_stay_win_g21' => [
            'label'       => 'Volton Stay & Win Business Γ21',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'variable',
            'fixedCharge' => 6.90,
            'priceKwh'    => null,
        ],
        'volton_stay_win_g22' => [
            'label'       => 'Volton Stay & Win Business Γ22',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'variable',
            'fixedCharge' => 6.90,
            'priceKwh'    => null,
        ],
        'volton_stay_win_g23' => [
            'label'       => 'Volton Stay & Win Business Γ23',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'variable',
            'fixedCharge' => 6.90,
            'priceKwh'    => null,
        ],
        'volton_yellow_simple_g21' => [
            'label'       => 'Volton Yellow Simple Business Γ21',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'variable',
            'fixedCharge' => 6.90,
            'priceKwh'    => null,
        ],
        // Πάγιο αμέτρητο (βλ. docblock κλάσης) — όχι μηδέν.
        'volton_yellow_simple_g22' => [
            'label'       => 'Volton Yellow Simple Business Γ22',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'variable',
            'fixedCharge' => null,
            'priceKwh'    => null,
        ],
        'volton_yellow_simple_g23' => [
            'label'       => 'Volton Yellow Simple Business Γ23',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'variable',
            'fixedCharge' => null,
            'priceKwh'    => null,
        ],
        'volton_green_eidiko_g21' => [
            'label'       => 'Volton Green Ειδικό Business Γ21',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'special',
            'fixedCharge' => 4.90,
            'priceKwh'    => null,
        ],
        'volton_green_eidiko_g22' => [
            'label'       => 'Volton Green Ειδικό Business Γ22',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'special',
            'fixedCharge' => null,
            'priceKwh'    => null,
        ],
        'volton_green_eidiko_g23' => [
            'label'       => 'Volton Green Ειδικό Business Γ23',
            'energyType'  => 'power',
            'category'    => 'business',
            'priceType'   => 'special',
            'fixedCharge' => null,
            'priceKwh'    => null,
        ],

        // --- Επαγγελματικό, αέριο ------------------------------------------
        'volton_gas_stay_win_g' => [
            'label'       => 'Volton Gas Stay & Win Business',
            'energyType'  => 'gas',
            'category'    => 'business',
            'priceType'   => 'variable',
            'fixedCharge' => 6.90,
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

    public static function exists(string $code): bool
    {
        return isset(self::PLANS[$code]);
    }

    /**
     * Κάθε πρόγραμμα ως γραμμή του `programs`, έτοιμο για seed.
     *
     * @return array<string, array{
     *   label: string, energyType: string, category: string, priceType: string,
     *   fixedCharge: float|null, priceKwh: float|null
     * }>
     */
    public static function all(): array
    {
        return self::PLANS;
    }

    /**
     * Μόνο τα προγράμματα μιας ενέργειας — `power` ή `gas`.
     *
     * Υπάρχει επειδή η `ECRM_Providers::seed()` χτίζει τα starters ανά ενέργεια
     * που πουλά ο πάροχος, και το κενό που διορθώνεται εκεί ήταν ακριβώς ότι το
     * αέριο δεν έπαιρνε ποτέ γραμμή.
     *
     * @return array<string, array{
     *   label: string, energyType: string, category: string, priceType: string,
     *   fixedCharge: float|null, priceKwh: float|null
     * }>
     */
    public static function forEnergy(string $energyType): array
    {
        return array_filter(
            self::PLANS,
            static fn (array $plan): bool => $plan['energyType'] === $energyType
        );
    }
}
