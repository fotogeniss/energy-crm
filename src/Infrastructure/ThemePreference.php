<?php

/**
 * Η εμφάνιση που έχει διαλέξει ένας χρήστης: ανοιχτή ή σκούρα.
 *
 * Ζει σε user meta και όχι σε πίνακα, για τον ίδιο ακριβώς λόγο που ζουν εκεί
 * τα αποθηκευμένα φίλτρα: ανήκει σε ένα άτομο, είναι μία τιμή, και κανένα
 * ερώτημα δεν τη διασχίζει ποτέ.
 *
 * ## Γιατί δική της κλάση και όχι μέθοδος του controller
 *
 * Έχει δύο καλούντες με διαφορετική φύση. Το REST τη γράφει· το κέλυφος σε PHP
 * τη διαβάζει **πριν τυπωθεί το πρώτο byte HTML**, ώστε το `data-theme` να
 * βρίσκεται ήδη στο markup και η σελίδα να μην τρεμοπαίξει από λευκό σε σκούρο.
 * Αν το κλειδί ζούσε μέσα στον controller, ο δεύτερος καλών θα το ήξερε δεύτερη
 * φορά — και ένα κλειδί user meta γραμμένο σε δύο σημεία είναι ακριβώς το
 * σχήμα λάθους που αδειάζει σιωπηλά τις προτιμήσεις όλων όταν αλλάξει το ένα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class ThemePreference
{
    public const LIGHT = 'light';
    public const DARK  = 'dark';

    /**
     * Προεπιλογή το ανοιχτό, και είναι απόφαση όχι παράλειψη: όποιος δεν έχει
     * διαλέξει βλέπει ό,τι έβλεπε χθες. Το σκούρο μπαίνει με πρόθεση.
     */
    public const FALLBACK = self::LIGHT;

    private const META_KEY = 'ecrm_theme';

    private function __construct()
    {
    }

    /**
     * Οι μόνες τιμές που γίνονται δεκτές, σε ένα σημείο.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        return [self::LIGHT, self::DARK];
    }

    /**
     * Ό,τι δεν είναι αναγνωρίσιμο διαβάζεται ως η προεπιλογή.
     *
     * Σκόπιμα ανεκτικό στην ανάγνωση: μια χαλασμένη τιμή στη βάση δεν πρέπει να
     * κρατήσει κανέναν έξω από την εφαρμογή του, και η γραφή είναι ήδη κλειστή.
     */
    public static function forUser(int $userId): string
    {
        if ($userId <= 0) {
            return self::FALLBACK;
        }

        $stored = get_user_meta($userId, self::META_KEY, true);

        return is_string($stored) && in_array($stored, self::allowed(), true)
            ? $stored
            : self::FALLBACK;
    }

    /**
     * Αποθηκεύει και επιστρέφει ό,τι ισχύει ΜΕΤΑ — όχι ό,τι ζητήθηκε.
     *
     * Έτσι ο καλών δεν χρειάζεται να μαντέψει αν η γραφή έγινε δεκτή: παίρνει
     * πίσω την αλήθεια και τη δείχνει.
     */
    public static function save(int $userId, string $theme): string
    {
        if ($userId <= 0 || ! in_array($theme, self::allowed(), true)) {
            return self::forUser($userId);
        }

        update_user_meta($userId, self::META_KEY, $theme);

        return $theme;
    }
}
