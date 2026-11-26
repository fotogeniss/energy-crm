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
 * Γ1, Γ21, Γ22, Γ23, και οι τρεις «Προωθητική Ενέργεια Stay & Win». Τα
 * πράσινα, το αέριο και τα παλιά μπλε δεν έχουν Ειδικούς Όρους: τυπώνεται η
 * αίτηση (και από το `EDITION` οι Γενικοί Όροι), ώσπου να έρθουν τα δικά τους.
 *
 * Οι σελίδες είναι Letter (215,9 × 279,4 mm), όπως τα PDF της Volton (οι
 * Γενικοί Όροι 215,9 × 303,0 mm, η αίτηση A4). Η
 * υπογραφή του πελάτη μπαίνει στη γραμμή «ΥΠΟΓΡΑΦΗ/ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ» της
 * τελευταίας σελίδας κάθε εγγράφου, μαζί με τόπο και ημερομηνία.
 *
 * Ό,τι υπογράφηκε πριν από το `FROM` κρατά το έντυπο πάνω στο οποίο υπογράφηκε
 * (K1, K3): μόνο την αίτηση.
 *
 * (457) Η Volton έστειλε (26/11/2026) νέα έκδοση της αίτησης και τους Γενικούς
 * Όρους Προμήθειας Ηλεκτρικής Ενέργειας σε Πελάτες Χαμηλής Τάσης, «που πρέπει
 * να συνοδεύονται». Από το `EDITION` κάθε αίτηση ρεύματος τυπώνει: νέα αίτηση,
 * Γενικοί Όροι (5 σελίδες), Ειδικοί Όροι του προγράμματος, Προωθητική. Η
 * παλιά αίτηση μένει ως `volton_he_v1` για ό,τι υπογράφηκε πάνω της. Και τα
 * πέντε νέα μπλε (σταθερά) από 23/11 έχουν πια τους Ειδικούς Όρους τους· τα
 * Fixed Plus και Προωθητική («Promo», οι 2 τελευταίες σελίδες του ίδιου PDF).
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

    /** (457) Η αίτηση όπως ήταν ως τις 26/11/2026. */
    public const APPLICATION_V1 = 'volton_he_v1';

    /** (457) Γενικοί Όροι Προμήθειας Ηλεκτρικής Ενέργειας σε Πελάτες ΧΤ. */
    public const GENERAL_TERMS = 'volton_go';

    /** Υπογεγραμμένες πριν από εδώ: μόνο η αίτηση, όπως υπογράφηκαν. */
    public const FROM = '2026-11-26 00:00:00';

    /** (457) Από εδώ: νέα αίτηση και Γενικοί Όροι. */
    public const EDITION = '2026-11-27 00:00:00';

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
        'volton_blue_fixed_plus_18m'     => 'volton_eo_blue_fixed_plus_18m',
        'volton_blue_flat_12m'           => 'volton_eo_blue_flat_12m',
        'volton_blue_fixed_plus_18m_g21' => 'volton_eo_blue_fixed_plus_18m_g21',
        'volton_blue_flat_24m_g21'       => 'volton_eo_blue_flat_24m_g21',
        'volton_blue_flat_12m_g21'       => 'volton_eo_blue_flat_12m_g21',
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
        'volton_blue_fixed_plus_18m'     => 'volton_promo_blue_fixed_plus_18m',
        'volton_blue_fixed_plus_18m_g21' => 'volton_promo_blue_fixed_plus_18m_g21',
    ];

    private function __construct()
    {
    }

    /**
     * Τα φύλλα με τη σειρά που τυπώνονται: αίτηση, Γενικοί Όροι, Ειδικοί
     * Όροι, Προωθητική.
     *
     * @return list<string>
     */
    public static function forProgram(string $code, string $signedAt = ''): array
    {
        $signed = trim($signedAt) !== '';

        if ($signed && $signedAt < self::FROM) {
            return [self::APPLICATION_V1];
        }

        $keys = $signed && $signedAt < self::EDITION
            ? [self::APPLICATION_V1]
            : [self::APPLICATION, self::GENERAL_TERMS];

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
            [self::APPLICATION, self::APPLICATION_V1, self::GENERAL_TERMS],
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
