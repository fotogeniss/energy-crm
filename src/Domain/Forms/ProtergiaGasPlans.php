<?php

/**
 * Τα δύο τιμολόγια φυσικού αερίου Protergia και το έντυπο που τυπώνει το καθένα.
 *
 * Ιδια λογική με τα `ProtergiaHomePlans`/`ProtergiaBizPlans`: η Protergia δίνει
 * ένα έντυπο ανά τιμολόγιο, με τον πίνακα τιμών της σελ. 2 ήδη τυπωμένο. Το
 * «ποιο πρόγραμμα διάλεξε ο πελάτης» είναι άρα το «ποιο έντυπο τυπώνεται».
 *
 * Μέχρι σήμερα κάθε σύμβαση αερίου Protergia τύπωνε το `protergia_fa` -- που
 * είναι, σελίδα προς σελίδα, οι δύο πρώτες σελίδες του Value Gas Sure (ίδια
 * pixel, ίδιος κωδικός ΕΟ.1250-2_94). Μένει ως η επιστροφή για ό,τι δεν είναι
 * ένα από τα δύο: μια σύμβαση πρέπει να τυπώνει το έντυπο πάνω στο οποίο
 * πουλήθηκε, όχι το σημερινό.
 *
 * ## Χρώμα
 *
 * Ο συνεργάτης (22/09/2026): «Σταθερά (μπλε τιμολόγιο): VALUE GAS SURE.
 * Κυμαινόμενα (κίτρινα τιμολόγια): SINGLE VALUE».
 *
 * ## Single ή Double
 *
 * Το ίδιο φύλλο (ΕΟ.1250-2_87) γράφει και το Double Value -- η μόνη διαφορά
 * είναι ότι ο πελάτης έχει και ρεύμα Protergia (έκπτωση 0,007 αντί 0,005
 * €/kWh). Ο συνεργάτης ζήτησε μόνο το Single· το Double δεν μπαίνει στο
 * dropdown μέχρι να ζητηθεί.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class ProtergiaGasPlans
{
    public const SURE   = 'protergia_fa_sure';
    public const SINGLE = 'protergia_fa_single';

    /**
     * plan code => [ετικέτα dropdown, χρώμα (price_type), πάγιο €/μήνα,
     *               χρέωση προμήθειας €/kWh όπου είναι σταθερή].
     *
     * Τα νούμερα όπως τα τυπώνει η σελ. 2 κάθε εντύπου (χωρίς ΦΠΑ):
     * - Value Gas Sure: 9,90 €/μήνα, 41,90 €/MWh (χωρίς την έκπτωση
     *   Power+Gas των 2,00 €/MWh -- όπως και στα οικιακά ρεύματος).
     * - Single Value: 5,00 €/μήνα, τιμή = TTF/1000 + 0,01 €/kWh μείον
     *   έκπτωση Value 0,005 -- καμία σταθερή τιμή, άρα null.
     *
     * Ο κωδικός είναι ΚΑΙ το όνομα του εντύπου (`assets/forms/protergia/<code>.json`).
     *
     * @var array<string, array{
     *   label: string, priceType: string, fixedCharge: float|null, priceKwh: float|null
     * }>
     */
    private const PLANS = [
        self::SURE => [
            'label'       => 'Protergia Φυσικό Αέριο — Οικιακό Αυτόνομο Value Gas Sure',
            'priceType'   => 'fixed',
            'fixedCharge' => 9.90,
            'priceKwh'    => 0.0419,
        ],
        self::SINGLE => [
            'label'       => 'Protergia Φυσικό Αέριο — Single Value',
            'priceType'   => 'variable',
            'fixedCharge' => 5.00,
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
     */
    public static function templateKey(string $plan): string
    {
        return self::exists($plan) ? $plan : '';
    }

    /**
     * @return array<string, array{
     *   label: string, priceType: string, fixedCharge: float|null, priceKwh: float|null
     * }>
     */
    public static function all(): array
    {
        return self::PLANS;
    }
}
