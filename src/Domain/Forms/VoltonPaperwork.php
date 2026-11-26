<?php

/**
 * (456) Τι τυπώνεται σε μια αίτηση ρεύματος Volton: η Αίτηση Σύναψης Σύμβασης,
 * οι Ειδικοί Όροι του προγράμματος, και η Προωθητική Ενέργεια όπου υπάρχει.
 *
 * Απόφαση ιδιοκτήτη 26/11/2026: «όταν ο πελάτης επιλέγει ένα πρόγραμμα θα
 * πρέπει να υπάρχει η σύμβαση, οι όροι και οι όροι του κάθε προγράμματος· όποιο
 * πρόγραμμα έχει από κάτω προωθητική, την κουμπώνεις κι αυτή». Ως τώρα τυπωνόταν
 * μόνο η αίτηση (`volton_he`, δύο σελίδες), όποιο πρόγραμμα κι αν διάλεγε ο
 * πωλητής, οπότε ο πελάτης υπέγραφε χωρίς τους όρους του τιμολογίου του.
 *
 * Πηγή: τα PDF «Ειδικοί Όροι Εμπορικού Προγράμματος» που έστειλε η Volton
 * (Διεύθυνση Πωλήσεων, 26/11/2026) για τα κίτρινα (κυμαινόμενα) προγράμματα
 * Γ1, Γ21, Γ22, Γ23, και οι τρεις «Προωθητική Ενέργεια Stay & Win». Τα μπλε
 * (σταθερά), τα πράσινα και το αέριο δεν ήρθαν: τυπώνεται μόνο η αίτηση, όπως
 * πριν, ώσπου να έρθουν τα δικά τους.
 *
 * Οι σελίδες είναι Letter (215,9 × 279,4 mm), όπως τα PDF της Volton. Η
 * υπογραφή του πελάτη μπαίνει στη γραμμή «ΥΠΟΓΡΑΦΗ/ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ» της
 * τελευταίας σελίδας κάθε εγγράφου, μαζί με τόπο και ημερομηνία.
 *
 * Ό,τι υπογράφηκε πριν από το `FROM` κρατά το έντυπο πάνω στο οποίο υπογράφηκε
 * (K1, K3): μόνο την αίτηση.
 *
 * Χωρίς WordPress, tested.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class VoltonPaperwork
{
    /** Η Αίτηση Σύναψης Σύμβασης Προμήθειας Ηλεκτρικής Ενέργειας. */
    public const APPLICATION = 'volton_he';

    /** Υπογεγραμμένες πριν από εδώ: μόνο η αίτηση, όπως υπογράφηκαν. */
    public const FROM = '2026-11-26 00:00:00';

    /** code προγράμματος => Ειδικοί Όροι. */
    private const TERMS = [
        'volton_yellow_protect_oct26' => 'volton_eo_yellow_protect',
        'volton_yellow_standard_g21'  => 'volton_eo_yellow_standard_g21',
        'volton_stay_win'             => 'volton_eo_stay_win',
        'volton_stay_win_g21'         => 'volton_eo_stay_win_g21',
        'volton_stay_win_g22'         => 'volton_eo_stay_win_g22',
        'volton_stay_win_g23'         => 'volton_eo_stay_win_g23',
        'volton_yellow_zero'          => 'volton_eo_yellow_zero',
        'volton_yellow_zero_g21'      => 'volton_eo_yellow_zero_g21',
        'volton_yellow_simple'        => 'volton_eo_yellow_simple',
        'volton_yellow_simple_g21'    => 'volton_eo_yellow_simple_g21',
        'volton_yellow_simple_g22'    => 'volton_eo_yellow_simple_g22',
        'volton_yellow_simple_g23'    => 'volton_eo_yellow_simple_g23',
    ];

    /**
     * code προγράμματος => Προωθητική Ενέργεια. Η «Stay & Win» της Volton έχει
     * ένα έγγραφο για Γ1 και Γ21 μαζί, και ένα για καθένα από τα Γ22, Γ23.
     */
    private const PROMO = [
        'volton_stay_win'     => 'volton_promo_stay_win_g1_g21',
        'volton_stay_win_g21' => 'volton_promo_stay_win_g1_g21',
        'volton_stay_win_g22' => 'volton_promo_stay_win_g22',
        'volton_stay_win_g23' => 'volton_promo_stay_win_g23',
    ];

    private function __construct()
    {
    }

    /**
     * Τα φύλλα με τη σειρά που τυπώνονται: αίτηση, Ειδικοί Όροι, Προωθητική.
     *
     * @return list<string>
     */
    public static function forProgram(string $code, string $signedAt = ''): array
    {
        $keys = [self::APPLICATION];

        if (trim($signedAt) !== '' && $signedAt < self::FROM) {
            return $keys;
        }

        if (isset(self::TERMS[$code])) {
            $keys[] = self::TERMS[$code];
        }

        if (isset(self::PROMO[$code])) {
            $keys[] = self::PROMO[$code];
        }

        return $keys;
    }

    public static function hasTerms(string $code): bool
    {
        return isset(self::TERMS[$code]);
    }

    /**
     * Κάθε φύλλο που πρέπει να υπάρχει στον δίσκο.
     *
     * @return list<string>
     */
    public static function templates(): array
    {
        return array_values(array_unique(array_merge(
            [self::APPLICATION],
            array_values(self::TERMS),
            array_values(self::PROMO)
        )));
    }

    /**
     * Τα προγράμματα με Ειδικούς Όρους.
     *
     * @return list<string>
     */
    public static function programsWithTerms(): array
    {
        return array_keys(self::TERMS);
    }
}
