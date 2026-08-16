<?php

/**
 * Everything that must be true before the PII backfill runs on a live site.
 *
 * HANDOVER §6.0.1 lists three things to check by hand before switching
 * ECRM_ENCRYPT_PII on in production. Two of them are questions a machine can
 * answer better than a person at midnight, so it answers them here; the third
 * is a promise about how the site is operated, and gets printed rather than
 * checked.
 *
 * ## The one that can waste the whole exercise
 *
 * FieldCipher takes its key from wp_salt('secure_auth'), which WordPress
 * assembles from SECURE_AUTH_KEY and SECURE_AUTH_SALT. When either constant is
 * missing from wp-config.php, WordPress does not fail — it quietly generates a
 * value and stores it in the options table. The encryption then works
 * perfectly, and the key sits in the same dump as the ciphertext.
 *
 * A backup handed to a developer would carry both halves. Every ΑΦΜ, ΑΔΤ and
 * address in that file would be readable by anyone holding it, while the
 * screen said the data was encrypted. That is worse than not encrypting, and
 * it is the failure this script exists to stop.
 *
 * ## It never prints a salt
 *
 * Only whether one is present. A preflight tool that echoes key material into
 * a terminal, a scrollback buffer or a CI log has become the leak it was
 * written to prevent.
 *
 *     php tools/preflight-encryption.php
 *     php tools/preflight-encryption.php /path/to/wordpress   (unusual layout)
 *
 * Exits 0 when the backfill may run, 1 when it may not. Safe to read-only: it
 * writes nothing and changes nothing.
 *
 * ## What it covers, stated because it once covered half
 *
 * PiiBackfill::sweep() walks two tables — customers, and the personal values
 * inside contracts.extra_json. Until 2026-08-16 this script reported only the
 * first, so a run could print every customer column at 100% and end with
 * "Καθαρό", while the contracts walk had not started. The blockers were always
 * complete; the progress report was not, and a report that cannot fail over
 * half its subject is the pattern HANDOVER §1 exists to catch.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

// --- Boot WordPress --------------------------------------------------------

$root = $argv[1] ?? dirname(__DIR__, 4);
$load = rtrim((string) $root, '/\\') . '/wp-load.php';

if (! is_readable($load)) {
    fwrite(STDERR, "Δεν βρέθηκε το wp-load.php στο {$root}\n");
    fwrite(STDERR, "Δώσε τη διαδρομή του WordPress ως όρισμα.\n");
    exit(1);
}

require_once $load;

use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Infrastructure\KeyFingerprint;
use EnergyCRM\Infrastructure\PiiBackfill;
use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\PiiBackfillRepository;
use EnergyCRM\Persistence\Tables;

// --- Reporting -------------------------------------------------------------

$blockers = 0;
$warnings = 0;

$report = static function (string $verdict, string $label, string $detail = '') use (&$blockers, &$warnings): void {
    $mark = match ($verdict) {
        'ok'    => '  [ΟΚ]     ',
        'warn'  => '  [ΠΡΟΣΟΧΗ]',
        default => '  [ΣΤΟΠ]   ',
    };

    if ($verdict === 'stop') {
        $blockers++;
    }

    if ($verdict === 'warn') {
        $warnings++;
    }

    echo $mark . ' ' . $label . "\n";

    if ($detail !== '') {
        echo '             ' . str_replace("\n", "\n             ", $detail) . "\n";
    }
};

/** A constant that is defined, non-empty, and not the shipped placeholder. */
$saltIsReal = static function (string $name): bool {
    if (! defined($name)) {
        return false;
    }

    $value = (string) constant($name);

    return $value !== ''
        && stripos($value, 'put your unique phrase here') === false
        && strlen($value) >= 32;
};

echo "\n";
echo "Έλεγχος πριν την κρυπτογράφηση PII — " . home_url() . "\n";
echo str_repeat('─', 64) . "\n\n";

// --- 1. Can this PHP encrypt at all? ---------------------------------------

if (FieldCipher::isAvailable()) {
    $report('ok', 'Το libsodium είναι διαθέσιμο.');
} else {
    $report(
        'stop',
        'Λείπει το libsodium — δεν γίνεται καμία κρυπτογράφηση.',
        'Ο FieldCipher πετάει MissingCipher αντί να αποθηκεύσει καθαρό κείμενο,'
        . "\nοπότε η καταχώριση θα σταματήσει. Ενεργοποίησε την επέκταση sodium."
    );
}

// --- 2. The salts. The check that can invalidate everything. ---------------

foreach (['SECURE_AUTH_KEY', 'SECURE_AUTH_SALT'] as $constant) {
    if ($saltIsReal($constant)) {
        $report('ok', "Το {$constant} είναι ορισμένο στο wp-config.php.");
        continue;
    }

    $report(
        'stop',
        "Το {$constant} ΛΕΙΠΕΙ ή είναι placeholder.",
        'Το WordPress θα παράξει τιμή και θα την αποθηκεύσει στον πίνακα options.'
        . "\nΤότε το κλειδί ταξιδεύει ΜΕΣΑ στο ίδιο dump με τα κρυπτογραφημένα"
        . "\nδεδομένα, και η κρυπτογράφηση δεν προστατεύει από την απειλή που"
        . "\nδηλώνει. ΜΗΝ τρέξεις το backfill. Βάλε πραγματικά salts πρώτα:"
        . "\nhttps://api.wordpress.org/secret-key/1.1/salt/"
    );
}

// Even with the constants present, a value in options means WordPress fell
// back at some point — worth knowing, because rows encrypted then used it.
foreach (['secure_auth_key', 'secure_auth_salt'] as $option) {
    if (get_option($option) !== false) {
        $report(
            'warn',
            "Υπάρχει τιμή «{$option}» στον πίνακα options.",
            'Σημαίνει ότι κάποια στιγμή το WordPress παρήγαγε salt μόνο του.'
            . "\nΑν υπάρχουν ήδη κρυπτογραφημένες γραμμές, ΜΗΝ σβήσεις αυτή τη"
            . "\nγραμμή: είναι το κλειδί τους."
        );
    }
}

// --- 2b. Is this still the key the data was written under? ------------------

$fingerprint = KeyFingerprint::default();

if (! $fingerprint->isRecorded()) {
    $report(
        'warn',
        'Δεν έχει καταγραφεί ακόμα αποτύπωμα κλειδιού.',
        'Καταγράφεται μόνο του με το ECRM_ENCRYPT_PII ανοιχτό. Μέχρι τότε μια'
        . "\nπεριστροφή του salt δεν ανιχνεύεται από πουθενά."
    );
} elseif ($fingerprint->matches()) {
    $report('ok', 'Το κλειδί είναι αυτό που έγραψε τα δεδομένα.');
} else {
    $report(
        'stop',
        'ΤΟ ΚΛΕΙΔΙ ΔΕΝ ΕΙΝΑΙ ΑΥΤΟ ΠΟΥ ΕΓΡΑΨΕ ΤΑ ΔΕΔΟΜΕΝΑ.',
        'Το SECURE_AUTH_SALT άλλαξε. Τα κρυπτογραφημένα πεδία διαβάζονται ως ΚΕΝΑ'
        . "\nκαι κάθε αποθήκευση γράφει αυτό το κενό πάνω τους — ΜΟΝΙΜΑ."
        . "\n\nΤΟ ΣΩΣΤΟ ΒΗΜΑ ΕΙΝΑΙ Η ΕΠΑΝΑΦΟΡΑ ΤΟΥ ΠΑΛΙΟΥ SALT, όχι το backfill:"
        . "\nμέχρι να γραφτεί κάτι από πάνω, το ciphertext είναι ακέραιο στον δίσκο"
        . "\nκαι ανακτάται ολόκληρο. Δες docs/BACKUP.md."
    );
}

// --- 3. The flag ------------------------------------------------------------

$enabled = defined('ECRM_ENCRYPT_PII') && constant('ECRM_ENCRYPT_PII') === true;

$report(
    'ok',
    'Σημαία ECRM_ENCRYPT_PII: ' . ($enabled ? 'ΑΝΟΙΧΤΗ' : 'κλειστή'),
    $enabled ? '' : 'Το backfill δεν κάνει τίποτα όσο είναι κλειστή — αυτό είναι σκόπιμο.'
);

// --- 4. What is actually in the table ---------------------------------------

global $wpdb;

$customers = Tables::name(Tables::CUSTOMERS);
$prefix    = FieldCipher::PREFIX;
$columns   = CustomerFields::encryptedColumns();

echo "\n  Γραμμές πελατών\n";
echo '  ' . str_repeat('─', 46) . "\n";

$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$customers}`");
printf("  %-22s %6d\n", 'σύνολο', $total);

foreach ($columns as $column) {
    $filled = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `{$customers}` WHERE `{$column}` IS NOT NULL AND `{$column}` <> ''"
    );

    $done = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$customers}` WHERE `{$column}` LIKE %s",
            $wpdb->esc_like($prefix) . '%'
        )
    );

    printf("  %-22s %6d με τιμή, %5d κρυπτογραφημένες\n", $column, $filled, $done);
}

// --- 4b. The other table the backfill sweeps --------------------------------

// Neither figure is a blocker. Work left to do is the reason to run a backfill,
// not an obstacle to running it — the only things that can say "do not run" are
// the salts, sodium and the blind index.
$pending = (new PiiBackfill(PiiBackfillRepository::default()))->pending();

echo "\n  Τι απομένει στη σάρωση\n";
echo '  ' . str_repeat('─', 46) . "\n";
// Spacing is written out rather than done with printf's %-38s: that pads by
// BYTES, and a Greek label is roughly two bytes per character, so the padding
// is spent before the label ends and nothing lines up. The table above escapes
// it only because its labels are column names, and those are ASCII.
printf("  πελάτες με καθαρό κείμενο             %6d\n", $pending['customers']);
printf("  συμβάσεις που δεν προσπέλασε η σάρωση %6d\n", $pending['contracts']);

echo "\n";

if ($pending['customers'] === 0 && $pending['contracts'] === 0) {
    $report('ok', 'Η σάρωση έχει τερματίσει και στους δύο πίνακες.');
} else {
    $report(
        'ok',
        'Η σάρωση δεν έχει τερματίσει — δεν είναι εμπόδιο, είναι η δουλειά.',
        'Τρέχει αυτόματα ανά ώρα σε παρτίδες, ή με το κουμπί «Μετατροπή τώρα»'
        . "\nστην οθόνη GDPR."
    );
}

// Same caveat the GDPR screen prints, for the same reason: the contracts figure
// is rows the walk has not reached, not rows still holding plaintext. Nothing
// can know the latter without opening every bag.
echo "             Ο αριθμός συμβάσεων είναι όσες δεν έχει προσπελάσει η σάρωση,\n";
echo "             όχι όσες κρατούν καθαρό κείμενο. Οι περισσότερες δεν θέλουν\n";
echo "             καμία αλλαγή.\n";

// --- 5. The blind index has to keep up --------------------------------------

$indexed = CustomerFields::INDEXED;
$indexCol = CustomerFields::INDEX_COLUMN;

$orphans = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$customers}`
     WHERE `{$indexed}` IS NOT NULL AND `{$indexed}` <> ''
       AND (`{$indexCol}` IS NULL OR `{$indexCol}` = '')"
);

echo "\n";

if ($orphans === 0) {
    $report('ok', "Κάθε γραμμή με {$indexed} έχει και {$indexCol}.");
} else {
    $report(
        'stop',
        "{$orphans} γραμμές έχουν {$indexed} χωρίς {$indexCol}.",
        'Χωρίς το blind index, η αναζήτηση και ο έλεγχος διπλοεγγραφής δεν'
        . "\nβρίσκουν αυτές τις γραμμές μόλις κρυπτογραφηθούν."
    );
}

// --- 6. What no script can verify -------------------------------------------

echo "\n";
echo "  Δύο κανόνες λειτουργίας — δεν ελέγχονται από εδώ\n";
echo '  ' . str_repeat('─', 46) . "\n";
echo "  1. Τα salts μπαίνουν στη διαδικασία backup ΔΙΠΛΑ στη βάση.\n";
echo "     Backup της βάσης χωρίς αυτά είναι μη αναστρέψιμο.\n";
echo "  2. ΠΟΤΕ περιστροφή του SECURE_AUTH_SALT μετά το backfill.\n";
echo "     Σήμερα μια περιστροφή χάνει λίγες πρόσφατες τιμές· μετά χάνει\n";
echo "     ΚΑΘΕ ΑΦΜ, ΑΔΤ και διεύθυνση, σιωπηλά, ως κενό πεδίο.\n";

// --- Verdict ----------------------------------------------------------------

echo "\n" . str_repeat('─', 64) . "\n";

if ($blockers > 0) {
    echo "ΜΗΝ ΤΡΕΞΕΙΣ ΤΟ BACKFILL — {$blockers} εμπόδιο(α).\n\n";
    exit(1);
}

echo $warnings > 0
    ? "Καθαρό, με {$warnings} προειδοποίηση(εις) — διάβασέ τες πριν συνεχίσεις.\n\n"
    : "Καθαρό. Το backfill μπορεί να τρέξει.\n\n";

exit(0);
