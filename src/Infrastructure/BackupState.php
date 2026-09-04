<?php

/**
 * Θυμάται πότε πάρθηκε το τελευταίο αντίγραφο μέσω tools/backup.php, ώστε η
 * απουσία του να φαίνεται σε μια οθόνη αντί να ανακαλύπτεται στην επαναφορά.
 *
 * Το tools/backup.php ήδη φτιάχνει ένα πλήρες manifest (αποτύπωμα κλειδιού,
 * αν μπήκαν τα uploads, πλήθη γραμμών) και το γράφει σε αρχείο έξω από το
 * site — σωστό για επαναφορά, άχρηστο για την οθόνη Υγεία, που δεν έχει πώς
 * να δει έναν εξωτερικό φάκελο. Αυτή η κλάση κρατά ΜΟΝΟ ό,τι χρειάζεται για
 * τρεις γραμμές σε πίνακα: πότε, με ποιο κλειδί, αν είχε έγγραφα, πόσες
 * γραμμές. ΚΑΜΙΑ διαδρομή δίσκου — ούτε του dump ούτε των salts. Το option
 * το διαβάζει ο manage_options, και δεν υπάρχει λόγος να λέει πού ζουν τα
 * αρχεία που ανοίγουν ΑΦΜ και ΑΔΤ.
 *
 * Το αποτύπωμα κλειδιού (KeyFingerprint) δεν είναι το κλειδί — είναι ήδη
 * σχεδιασμένο να αποθηκεύεται με ασφάλεια, όπως κάνει το ίδιο το
 * ecrm_pii_key_fingerprint. Η σύγκρισή του με το τρέχον κλειδί εδώ είναι
 * ακριβώς ο έλεγχος που το BACKUP.md ζητά να γίνεται ΠΡΙΝ την επαναφορά:
 * αν διαφωνούν, το αντίγραφο γράφτηκε με άλλο κλειδί από το σημερινό, και η
 * επαναφορά θα γράψει κενά πάνω σε δεδομένα που ακόμα ανοίγουν.
 *
 * Μη autoloaded option (τρίτη παράμετρος false στο update_option): το
 * διαβάζει μόνο η σελίδα Υγεία, όχι κάθε request — ίδια λογική με το
 * ecrm_error_log.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class BackupState
{
    public const OPTION = 'ecrm_last_backup';

    /**
     * Καταγράφει ένα ολοκληρωμένο αντίγραφο, από το ίδιο manifest που ήδη
     * γράφει το tools/backup.php στο δίσκο.
     *
     * @param array<string, mixed> $manifest Το $payload του tools/backup.php.
     */
    public static function record(array $manifest): void
    {
        $uploads = $manifest['uploads'] ?? [];
        $rows    = $manifest['rows'] ?? [];

        update_option(self::OPTION, [
            'created_at'       => (string) ($manifest['created_at'] ?? ''),
            'key_fingerprint'  => (string) ($manifest['key_fingerprint'] ?? ''),
            'uploads_included' => is_array($uploads) ? (bool) ($uploads['included'] ?? false) : false,
            'rows'             => is_array($rows) ? $rows : [],
        ], false);

        // Το option κρατά ΜΟΝΟ το τελευταίο αντίγραφο, οπότε δεν μπορεί να
        // δείξει κενό: τρεις μέρες χωρίς backup και μετά ένα καθαρό δείχνουν
        // ίδια με τριάντα συνεχόμενες μέρες. Ο μετρητής κρατά τις ημέρες.
        Metrics::bump(Metrics::BACKUP);
    }

    /**
     * @return array{created_at: string, key_fingerprint: string, uploads_included: bool, rows: array<string, int>}|null
     *   null όταν δεν έχει τρέξει ποτέ το εργαλείο αντιγράφων σε αυτό το site.
     */
    public static function last(): ?array
    {
        $stored = get_option(self::OPTION, null);

        if (! is_array($stored) || ! isset($stored['created_at'])) {
            return null;
        }

        return [
            'created_at'       => (string) $stored['created_at'],
            'key_fingerprint'  => (string) ($stored['key_fingerprint'] ?? ''),
            'uploads_included' => (bool) ($stored['uploads_included'] ?? false),
            'rows'             => is_array($stored['rows'] ?? null) ? $stored['rows'] : [],
        ];
    }

    /** Μέρες από το τελευταίο αντίγραφο, ή null αν δεν υπάρχει κανένα. */
    public static function daysSinceLast(): ?int
    {
        $last = self::last();

        if ($last === null || $last['created_at'] === '') {
            return null;
        }

        $then = strtotime($last['created_at']);

        if ($then === false) {
            return null;
        }

        return (int) floor((time() - $then) / DAY_IN_SECONDS);
    }
}
