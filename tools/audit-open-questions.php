<?php
/**
 * Οι τέσσερις ερωτήσεις που ο έλεγχος backend δεν μπορούσε να απαντήσει μόνος.
 *
 * Το `docs/AUDIT-BACKEND.md` κατέγραψε 20 ευρήματα διαβάζοντας κώδικα. Τέσσερα
 * από αυτά είναι «ναι, αλλά ισχύει στα ΔΙΚΑ σου δεδομένα;» — και η απάντηση
 * αλλάζει τι πρέπει να γίνει, όχι μόνο πόσο επείγει.
 *
 * Δεν γράφει τίποτα: μόνο SELECT και COUNT, κανένα DDL. Δεν τυπώνει ποτέ ΑΦΜ,
 * ΑΔΤ, όνομα ή διεύθυνση — μόνο πλήθη και ημερομηνίες, ώστε η έξοδος να
 * επικολλάται χωρίς δεύτερη σκέψη.
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/audit-open-questions.php
 *
 * ## Δύο πράγματα που έμαθε το πρώτο τρέξιμο
 *
 * Είχε `declare(strict_types=1)`. Το `wp eval-file` σε ορισμένες εκδόσεις
 * περνά το αρχείο από `eval()`, όπου η δήλωση είναι **fatal** — «must be the
 * very first statement in the script». Ένα διαγνωστικό δεν έχει λόγο να
 * απαιτεί κάτι που μπορεί να το σκοτώσει πριν πει λέξη.
 *
 * Και πέθαινε σιωπηλά: με `WP_DEBUG=false` ο χρήστης έβλεπε «There has been a
 * critical error». Τώρα τυπώνει το σφάλμα ο ίδιος. Ένα εργαλείο ελέγχου που
 * δεν λέει γιατί απέτυχε είναι ένα δεύτερο πρόβλημα, όχι βοήθεια.
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

@ini_set('display_errors', '1'); // phpcs:ignore
error_reporting(E_ALL);

try {
    global $wpdb;

    $p = $wpdb->prefix . 'ecrm_';

    /** Πόσες γραμμές δίνει ένα COUNT, ή -1 όταν το ερώτημα απέτυχε. */
    $count = static function (string $sql) use ($wpdb) {
        $suppress = $wpdb->suppress_errors(true);
        $value    = $wpdb->get_var($sql); // phpcs:ignore
        $failed   = $wpdb->last_error !== '';
        $wpdb->suppress_errors($suppress);

        return $failed ? -1 : (int) $value;
    };

    $say = static function ($label, $n, $meaning = '') {
        $shown = $n < 0 ? 'ΔΕΝ ΑΠΑΝΤΗΣΕ (λείπει ο πίνακας;)' : (string) $n;
        echo '  ' . str_pad($label, 44, '.') . ' ' . $shown
            . ($meaning !== '' ? '   <- ' . $meaning : '') . "\n";
    };

    $encrypted = class_exists('EnergyCRM\Persistence\CustomerFields')
        && \EnergyCRM\Persistence\CustomerFields::isEnabled();

    echo "\n=== Energy CRM - ανοιχτες ερωτησεις του ελεγχου backend ===\n";
    echo 'Ημερομηνία: ' . gmdate('Y-m-d H:i') . " UTC\n";
    echo 'Prefix: ' . $p . "\n";
    echo 'Κρυπτογράφηση PII: ' . ($encrypted ? 'ΕΝΕΡΓΗ' : 'ανενεργή') . "\n";

    // ------------------------------------------------------------ ευρημα 6 --
    echo "\n[6] Οι διαδρομές /sign/{token} - υπάρχουν παλιά διαπιστευτήρια;\n";
    echo "    Κάθε γραμμή χωρίς signed_at είναι σύνδεσμος υπογραφής που δεν λήγει,\n";
    echo "    δεν έχει rate limit και παρακάμπτει τον γράφο μεταβάσεων.\n";

    $say('signatures - σύνολο', $count("SELECT COUNT(*) FROM {$p}signatures"));
    $say(
        'signatures - ΑΝΥΠΟΓΡΑΦΕΣ (ζωντανά tokens)',
        $count("SELECT COUNT(*) FROM {$p}signatures WHERE signed_at IS NULL"),
        'αν > 0, οι διαδρομές κλείνουν πριν σβηστούν'
    );

    $newest = $wpdb->get_var("SELECT MAX(created_at) FROM {$p}signatures"); // phpcs:ignore
    echo '  ' . str_pad('signatures - νεότερη γραμμή', 44, '.') . ' ' . ($newest ? $newest : '-') . "\n";

    // ----------------------------------------------------------- ευρημα 10 --
    echo "\n[10] GDPR - εργασίες που δεν εξάγονται ούτε σβήνονται\n";
    echo "     Ο PersonalDataTables φτάνει στο tasks ΜΟΝΟ μέσω contract_id.\n";

    $say(
        'tasks με customer_id, χωρίς contract_id',
        $count("SELECT COUNT(*) FROM {$p}tasks WHERE customer_id IS NOT NULL AND contract_id IS NULL"),
        'αόρατες σε export ΚΑΙ σε erase'
    );

    // ----------------------------------------------------------- ευρημα 11 --
    echo "\n[11] Ο δείκτης afm_hash - υπολογίστηκε πάνω σε κρυπτοκείμενο;\n";
    echo "     Αν ναι, ο έλεγχος διπλοτύπων λέει «κανένα» για πάντα, σιωπηλά.\n";

    $say('customers - σύνολο', $count("SELECT COUNT(*) FROM {$p}customers"));
    $say('με ΑΦΜ (μη κενό)', $count("SELECT COUNT(*) FROM {$p}customers WHERE afm IS NOT NULL AND afm <> ''"));
    $say('με κρυπτογραφημένο ΑΦΜ', $count("SELECT COUNT(*) FROM {$p}customers WHERE afm LIKE 'ecrm1:%'"));
    $say(
        'κρυπτο-ΑΦΜ + συμπληρωμένο hash',
        $count("SELECT COUNT(*) FROM {$p}customers WHERE afm LIKE 'ecrm1:%' AND afm_hash IS NOT NULL AND afm_hash <> ''"),
        'δεν αποδεικνύει βλάβη - δες τη δοκιμή'
    );
    $say(
        'με ΑΦΜ αλλά ΧΩΡΙΣ hash',
        $count("SELECT COUNT(*) FROM {$p}customers WHERE afm IS NOT NULL AND afm <> '' AND (afm_hash IS NULL OR afm_hash = '')"),
        'αυτοί δεν βρίσκονται ποτέ ως διπλότυπα'
    );

    /*
     * Η ζωντανή δοκιμή. Το πλήθος από πάνω ΔΕΝ αποδεικνύει βλάβη: μια γραμμή
     * μπορεί να πήρε σωστό hash από το forStorage() και να κρυπτογραφήθηκε
     * μετά. Το οριστικό τεστ είναι να αποκρυπτογραφηθεί το ΑΦΜ, να
     * ξαναϋπολογιστεί ο δείκτης και να συγκριθεί. Καμία τιμή δεν τυπώνεται.
     */
    if (class_exists('EnergyCRM\Persistence\CustomerFields')) {
        $sample = $wpdb->get_results( // phpcs:ignore
            "SELECT afm, afm_hash FROM {$p}customers
             WHERE afm IS NOT NULL AND afm <> '' AND afm_hash IS NOT NULL AND afm_hash <> ''
             LIMIT 100",
            ARRAY_A
        );

        if (is_array($sample) && $sample !== []) {
            $fields   = \EnergyCRM\Persistence\CustomerFields::default();
            $ok       = 0;
            $mismatch = 0;
            $unread   = 0;

            foreach ($sample as $row) {
                $plain = $fields->fromStorage(['afm' => (string) $row['afm']]);
                $plain = isset($plain['afm']) ? (string) $plain['afm'] : '';

                if ($plain === '') {
                    $unread++;
                    continue;
                }

                if (hash_equals((string) $row['afm_hash'], $fields->index($plain))) {
                    $ok++;
                } else {
                    $mismatch++;
                }
            }

            echo "\n     Ζωντανή δοκιμή σε " . count($sample) . " γραμμές:\n";
            $say('δείκτης ΣΩΣΤΟΣ', $ok);
            $say('δείκτης ΧΑΛΑΣΜΕΝΟΣ', $mismatch, $mismatch > 0 ? 'ΕΠΙΒΕΒΑΙΩΜΕΝΟ ΠΡΟΒΛΗΜΑ' : '');
            $say('δεν αποκρυπτογραφήθηκε', $unread, $unread > 0 ? 'άλλαξαν τα salts;' : '');
        } else {
            echo "\n     (καμία γραμμή με ΑΦΜ και δείκτη - δεν εφαρμόζεται)\n";
        }
    }

    // ------------------------------------------------------------- πλαισιο --
    echo "\n[πλαίσιο] Αριθμοί που αλλάζουν την προτεραιότητα των υπολοίπων\n";

    $say('κανόνες προμήθειας (ενεργοί)', $count("SELECT COUNT(*) FROM {$p}commission_rules WHERE active = 1"));
    $say('εκκαθαρίσεις - σύνολο', $count("SELECT COUNT(*) FROM {$p}payouts"));
    $say('εκκαθαρίσεις - πληρωμένες', $count("SELECT COUNT(*) FROM {$p}payouts WHERE status = 'paid'"));
    $say(
        'πληρωμένες ΑΛΛΑ ακυρωμένες συμβάσεις',
        $count("SELECT COUNT(*) FROM {$p}contracts WHERE payout_id IS NOT NULL AND payout_id > 0 AND status IN ('cancelled','terminated')"),
        'εύρημα 4 - προμήθεια χωρίς επιστροφή'
    );
    $say('συμβάσεις - σύνολο', $count("SELECT COUNT(*) FROM {$p}contracts"));
    $say('files - σύνολο', $count("SELECT COUNT(*) FROM {$p}files"));
    $say(
        'files χωρίς σύμβαση (contract_id NULL)',
        $count("SELECT COUNT(*) FROM {$p}files WHERE contract_id IS NULL"),
        'αόρατα σε καθαρισμό ΚΑΙ σε GDPR'
    );

    echo "\n=== τελος - καμια εγγραφη δεν εγινε ===\n\n";
} catch (\Throwable $e) {
    echo "\n!!! ΤΟ SCRIPT ΕΣΠΑΣΕ — αυτό χρειάζομαι:\n";
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
    echo 'στο ' . $e->getFile() . ':' . $e->getLine() . "\n\n";
}
