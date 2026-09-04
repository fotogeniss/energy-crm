<?php

/**
 * Πότε έτρεξε πράγματι κάθε προγραμματισμένη εργασία -- όχι πότε λέει το
 * WordPress ότι θα ξανατρέξει.
 *
 * Η Υγεία ήδη ρωτά το `wp_next_scheduled()` για τις τέσσερις cron εργασίες
 * του plugin, και αυτό απαντά ΜΟΝΟ αν υπάρχει καταχώριση στον πίνακα cron --
 * όχι αν εκτελέστηκε ποτέ. Το WP-Cron είναι ψευδο-cron: ενεργοποιείται από
 * επισκέψεις στο site. Σε site χωρίς επισκεψιμότητα (ή με DISABLE_WP_CRON
 * χωρίς πραγματικό system cron από πίσω, ήδη ελεγμένο ξεχωριστά) η
 * εγγραφή μένει «προγραμματισμένη για χθες» επ' άπειρον, και ο υπάρχων
 * έλεγχος μένει πράσινος γιατί δεν ρωτά ποτέ αν τρέχει.
 *
 * Κάθε εργασία καλεί mark() ως ΠΡΩΤΗ γραμμή του callback της -- πριν από
 * οποιαδήποτε συνθήκη διακοπής (π.χ. το PiiBackfill μπλοκάρεται όταν η
 * κρυπτογράφηση είναι κλειστή). Καταγράφει ότι το WP-Cron πράγματι
 * ενεργοποίησε το hook, ανεξάρτητα από το αν η ίδια η δουλειά είχε κάτι να
 * κάνει -- δύο διαφορετικές ερωτήσεις, ίδιο σχήμα με το ErrorLog που ξεχωρίζει
 * «σπάσαμε εμείς» από «ο χρήστης έκανε κάτι λάθος».
 *
 * Ένα option, όλα τα hooks μαζί -- τέσσερις εγγραφές δεν χρειάζονται τέσσερα
 * options.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class Heartbeat
{
    private const OPTION = 'ecrm_cron_heartbeats';

    public static function mark(string $hook): void
    {
        $all = get_option(self::OPTION, []);

        if (! is_array($all)) {
            $all = [];
        }

        $all[$hook] = time();

        update_option(self::OPTION, $all, false);
    }

    /** Unix timestamp της τελευταίας γνωστής εκτέλεσης, ή null αν καμία δεν έχει καταγραφεί ακόμη. */
    public static function lastRun(string $hook): ?int
    {
        $all = get_option(self::OPTION, []);

        return isset($all[$hook]) && is_int($all[$hook]) ? $all[$hook] : null;
    }
}
