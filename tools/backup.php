<?php

/**
 * Ένα αντίγραφο ασφαλείας που δεν μπορεί να ξεχάσει τα salts.
 *
 * Το dump της βάσης είναι το εύκολο μέρος και το ξέρουν όλα τα εργαλεία. Αυτό
 * που ξεχνιέται είναι τα salts, και από τη στιγμή που το backfill τερμάτισε ένα
 * dump χωρίς αυτά δεν είναι μερικό αντίγραφο — είναι **άχρηστο**: κάθε ΑΦΜ, ΑΔΤ
 * και διεύθυνση μέσα του δεν ανοίγει ποτέ ξανά.
 *
 * ## Τα δύο πράγματα που κάνει και δεν τα κάνει άλλο εργαλείο
 *
 * **Επιβάλλει τον διαχωρισμό.** Ζητά δύο διαδρομές και αρνείται αν η μία είναι
 * μέσα στην άλλη. Το Export του Local βάζει βάση και `wp-config.php` στο ίδιο
 * αρχείο· είναι βολικό, και είναι ακριβώς το σενάριο που η κρυπτογράφηση υπάρχει
 * για να επιβιώσει. Εργαλείο που το επιτρέπει σιωπηλά ενσωματώνει το λάθος.
 *
 * **Κάνει το ζευγάρι αποδείξιμο.** Και τα δύο αρχεία κουβαλούν το ίδιο
 * αποτύπωμα κλειδιού (`KeyFingerprint`) — που δεν είναι το κλειδί και είναι
 * ασφαλές να αποθηκευτεί. Χωρίς αυτό, δύο αρχεία από διαφορετικές μέρες
 * μοιάζουν ίδια, και το λάθος ζευγάρωμα φαίνεται μόνο αφού η επαναφορά έχει
 * γίνει και καμία καρτέλα δεν δείχνει ΑΦΜ.
 *
 *     php tools/backup.php <φάκελος-dump> <φάκελος-μυστικών> [--with-uploads]
 *     php tools/backup.php --verify <manifest.json>
 *
 * ## Γιατί το `uploads` είναι προαιρετικό και όχι προεπιλογή
 *
 * Εκεί ζουν οι σαρωμένες ταυτότητες, οι λογαριασμοί, οι υπογραφές και τα
 * παραγμένα PDF — δηλαδή τα πιο ευαίσθητα αρχεία του συστήματος, και τα μόνα
 * που η βάση δεν περιέχει: κρατά μόνο γραμμές που δείχνουν σε αυτά. Αντίγραφο
 * χωρίς αυτά επαναφέρει κάθε σύμβαση με σπασμένους συνδέσμους.
 *
 * Μένει προαιρετικό επειδή είναι το μόνο μέρος που μπορεί να είναι γιγαμπάιτ,
 * και εργαλείο που αντιγράφει σιωπηλά γιγαμπάιτ γεμίζει κάποτε έναν δίσκο. Ο
 * συμβιβασμός: μετριέται και τυπώνεται ΠΡΙΝ αντιγραφεί, και πάνω από το όριο
 * σταματά με οδηγία αντί να προσπαθήσει.
 *
 * Το `--verify` απαντά αν το τρέχον κλειδί είναι αυτό που έγραψε εκείνο το
 * αντίγραφο. Τρέξε το ΠΡΙΝ βασιστείς σε ένα αντίγραφο, όχι μετά.
 *
 * Επιστρέφει 1 σε οτιδήποτε πήγε στραβά, οπότε μπορεί να μπει σε scheduled task.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$load = rtrim((string) $root, '/\\') . '/wp-load.php';

if (! is_readable($load)) {
    fwrite(STDERR, "Δεν βρέθηκε το wp-load.php στο {$root}\n");
    exit(1);
}

require_once $load;

use EnergyCRM\Infrastructure\BackupState;
use EnergyCRM\Infrastructure\KeyFingerprint;
use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\Tables;

/** Τα οκτώ. Όλα, γιατί το να ξεχωρίζεις ποιο χρειάζεσαι τη στιγμή της επαναφοράς
 * είναι ο χειρότερος δυνατός χρόνος για να το σκεφτείς. */
const ECRM_SALT_CONSTANTS = [
    'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
    'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
];

$fail = static function (string $message): never {
    fwrite(STDERR, "\nΣΤΟΠ: {$message}\n\n");
    exit(1);
};

// --- Λειτουργία επαλήθευσης --------------------------------------------------

if (($argv[1] ?? '') === '--verify') {
    $manifestPath = $argv[2] ?? '';

    if (! is_readable($manifestPath)) {
        $fail("Δεν διαβάζεται το manifest: {$manifestPath}");
    }

    $manifest = json_decode((string) file_get_contents($manifestPath), true);

    if (! is_array($manifest) || ! isset($manifest['key_fingerprint'])) {
        $fail('Το manifest δεν έχει αποτύπωμα κλειδιού — δεν είναι αρχείο αυτού του εργαλείου.');
    }

    $now = KeyFingerprint::default()->current();

    echo "\nΑντίγραφο: " . ($manifest['created_at'] ?? 'άγνωστης ημερομηνίας') . "\n";

    // Πριν από το κλειδί, γιατί ισχύει ακόμα κι όταν το κλειδί ταιριάζει: ένα
    // αντίγραφο χωρίς έγγραφα επαναφέρει κάθε σύμβαση με σπασμένους συνδέσμους,
    // και αυτό δεν φαίνεται από πουθενά αλλού μέχρι να ψάξει κάποιος ταυτότητα.
    $uploads = $manifest['uploads'] ?? ['included' => false, 'files' => 0];

    echo empty($uploads['included'])
        ? "[ΠΡΟΣΟΧΗ] Χωρίς έγγραφα: ταυτότητες, υπογραφές και PDF δεν είναι μέσα.\n"
        : '[ΟΚ] Περιέχει ' . (int) $uploads['files'] . " αρχεία εγγράφων.\n";

    if (hash_equals((string) $manifest['key_fingerprint'], $now)) {
        echo "[ΟΚ] Το τρέχον κλειδί είναι αυτό που έγραψε αυτό το αντίγραφο.\n\n";
        exit(0);
    }

    echo "[ΣΤΟΠ] ΤΟ ΚΛΕΙΔΙ ΔΕΝ ΤΑΙΡΙΑΖΕΙ ΜΕ ΑΥΤΟ ΤΟ ΑΝΤΙΓΡΑΦΟ.\n";
    echo "       Επαναφορά αυτού του dump με τα σημερινά salts θα έδινε βάση\n";
    echo "       όπου κανένα ΑΦΜ, ΑΔΤ ή διεύθυνση δεν ανοίγει. Βρες το αρχείο\n";
    echo "       salts που βγήκε ΜΑΖΙ του — έχει το ίδιο αποτύπωμα.\n\n";
    exit(1);
}

// --- Λειτουργία δημιουργίας --------------------------------------------------

$withUploads = in_array('--with-uploads', $argv, true);

$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $arg): bool => ! str_starts_with($arg, '--')
));

$dumpDir    = $positional[0] ?? '';
$secretsDir = $positional[1] ?? '';

if ($dumpDir === '' || $secretsDir === '') {
    fwrite(STDERR, "\nΧρήση:\n");
    fwrite(STDERR, "  php tools/backup.php <φάκελος-dump> <φάκελος-μυστικών> [--with-uploads]\n");
    fwrite(STDERR, "  php tools/backup.php --verify <manifest.json>\n\n");
    fwrite(STDERR, "Οι δύο φάκελοι πρέπει να είναι ΔΙΑΦΟΡΕΤΙΚΟΙ και ο ένας εκτός του άλλου.\n\n");
    exit(1);
}

$resolve = static function (string $path) use ($fail): string {
    if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
        $fail("Δεν δημιουργήθηκε ο φάκελος: {$path}");
    }

    $real = realpath($path);

    if ($real === false) {
        $fail("Δεν επιλύεται η διαδρομή: {$path}");
    }

    return rtrim($real, '/\\');
};

$dump    = $resolve($dumpDir);
$secrets = $resolve($secretsDir);

$inside = static fn (string $a, string $b): bool
    => $a === $b || str_starts_with($a . DIRECTORY_SEPARATOR, $b . DIRECTORY_SEPARATOR);

if ($inside($dump, $secrets) || $inside($secrets, $dump)) {
    $fail(
        "Οι δύο φάκελοι δεν επιτρέπεται να είναι ο ίδιος ή ο ένας μέσα στον άλλο.\n"
        . "     Το κλειδί δίπλα στο ciphertext ακυρώνει ολόκληρη την κρυπτογράφηση —\n"
        . "     είναι το σενάριο για το οποίο υπάρχει. Δες docs/BACKUP.md."
    );
}

// Κανένα από τα δύο κάτω από το web root. Ένα .sql με ΑΦΜ και ΑΔΤ σε διαδρομή
// που σερβίρει ο web server είναι διαρροή, όχι αντίγραφο.
$abspath = rtrim((string) realpath(ABSPATH), '/\\');

foreach (['dump' => $dump, 'μυστικών' => $secrets] as $label => $candidate) {
    if ($inside($candidate, $abspath)) {
        $fail(
            "Ο φάκελος {$label} είναι ΜΕΣΑ στο web root ({$abspath}).\n"
            . "     Ό,τι μπαίνει εκεί μπορεί να το κατεβάσει ο καθένας. Διάλεξε\n"
            . "     διαδρομή εκτός του site."
        );
    }
}

$stamp       = current_time('Ymd-Hi');
$fingerprint = KeyFingerprint::default();
$dumpFile    = $dump . DIRECTORY_SEPARATOR . "crm-{$stamp}.sql";
$saltsFile   = $secrets . DIRECTORY_SEPARATOR . "crm-{$stamp}.salts.php";
$manifest    = $dump . DIRECTORY_SEPARATOR . "crm-{$stamp}.manifest.json";

echo "\nΑντίγραφο ασφαλείας — " . home_url() . "\n";
echo str_repeat('─', 64) . "\n\n";

// --- 1. Τα salts, πρώτα ------------------------------------------------------

// Πρώτα επίτηδες: αν λείπει salt, δεν έχει νόημα να παραχθεί dump που κανείς
// δεν θα μπορεί να διαβάσει.
$missing = array_values(array_filter(
    ECRM_SALT_CONSTANTS,
    static fn (string $name): bool => ! defined($name) || (string) constant($name) === ''
));

if ($missing !== []) {
    $fail(
        'Λείπουν σταθερές από το wp-config.php: ' . implode(', ', $missing) . "\n"
        . "     Το WordPress παράγει τιμή μόνο του και τη βάζει στον πίνακα options,\n"
        . "     δηλαδή ΜΕΣΑ στο dump. Δες tools/preflight-encryption.php."
    );
}

$lines = ["<?php\n", "\n"];
$lines[] = "// Salts του " . home_url() . ", " . current_time('Y-m-d H:i') . ".\n";
$lines[] = "// Αποτύπωμα κλειδιού: " . $fingerprint->current() . "\n";
$lines[] = "// ΤΑΙΡΙΑΖΕΙ ΜΕ: crm-{$stamp}.sql — μόνο με αυτό.\n";
$lines[] = "//\n";
$lines[] = "// Επαναφορά: αντικατέστησε τις αντίστοιχες γραμμές του wp-config.php\n";
$lines[] = "// ΠΡΙΝ φορτώσεις τη βάση. Δες docs/BACKUP.md.\n\n";

foreach (ECRM_SALT_CONSTANTS as $name) {
    $lines[] = sprintf("define('%s', %s);\n", $name, var_export((string) constant($name), true));
}

if (file_put_contents($saltsFile, implode('', $lines)) === false) {
    $fail("Δεν γράφτηκε το αρχείο salts: {$saltsFile}");
}

@chmod($saltsFile, 0600);

echo "  [ΟΚ]  Salts        → {$saltsFile}\n";

// --- 2. Το dump --------------------------------------------------------------

$quiet = static function (string $command): bool {
    $output = [];
    $code   = 0;
    @exec($command . ' 2>&1', $output, $code);

    return $code === 0;
};

$exported = false;

if ($quiet('wp --version')) {
    $exported = $quiet(sprintf(
        'wp db export %s --path=%s',
        escapeshellarg($dumpFile),
        escapeshellarg(ABSPATH)
    ));

    if ($exported) {
        echo "  [ΟΚ]  Βάση (wp)   → {$dumpFile}\n";
    }
}

if (! $exported && $quiet('mysqldump --version')) {
    // Ο κωδικός μέσω περιβάλλοντος, όχι στη γραμμή εντολών: εκεί τον βλέπει
    // η λίστα διεργασιών και τον κρατά το ιστορικό του shell.
    putenv('MYSQL_PWD=' . DB_PASSWORD);

    [$host, $port] = array_pad(explode(':', (string) DB_HOST, 2), 2, '');

    $exported = $quiet(sprintf(
        'mysqldump --host=%s%s --user=%s --single-transaction --default-character-set=utf8mb4 %s > %s',
        escapeshellarg($host),
        $port !== '' ? ' --port=' . escapeshellarg($port) : '',
        escapeshellarg((string) DB_USER),
        escapeshellarg((string) DB_NAME),
        escapeshellarg($dumpFile)
    ));

    putenv('MYSQL_PWD');

    if ($exported) {
        echo "  [ΟΚ]  Βάση (dump) → {$dumpFile}\n";
    }
}

if (! $exported || ! is_file($dumpFile) || filesize($dumpFile) === 0) {
    @unlink($saltsFile);

    $fail(
        "Το dump της βάσης απέτυχε — δεν βρέθηκε ούτε `wp` ούτε `mysqldump`,\n"
        . "     ή η εντολή επέστρεψε σφάλμα. Το αρχείο salts διαγράφηκε: μισό\n"
        . "     αντίγραφο που μοιάζει ολόκληρο είναι χειρότερο από κανένα."
    );
}

// --- 2b. Τα έγγραφα, αν ζητήθηκαν -------------------------------------------

/** Το μέγεθος πάνω από το οποίο σταματά αντί να προσπαθήσει. */
const ECRM_UPLOADS_CAP_BYTES = 500 * 1024 * 1024;

$uploadsReport = ['included' => false, 'files' => 0, 'bytes' => 0];
$uploadsSource = (string) (wp_upload_dir()['basedir'] ?? '');

if ($withUploads) {
    if (! is_dir($uploadsSource)) {
        $fail("Δεν βρέθηκε ο φάκελος uploads: {$uploadsSource}");
    }

    // Μέτρηση πρώτα, αντιγραφή μετά. Το νούμερο τυπώνεται ώστε να μη γίνει ποτέ
    // αντιγραφή που ο χειριστής δεν περίμενε.
    $files = 0;
    $bytes = 0;

    /** @var SplFileInfo $item */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsSource, FilesystemIterator::SKIP_DOTS)
    ) as $item) {
        if ($item->isFile()) {
            $files++;
            $bytes += (int) $item->getSize();
        }
    }

    printf("  ...   uploads     %d αρχεία, %s MB\n", $files, number_format($bytes / 1048576, 1));

    if ($bytes > ECRM_UPLOADS_CAP_BYTES) {
        $fail(
            'Ο φάκελος uploads είναι ' . number_format($bytes / 1048576, 1) . " MB — πάνω από το όριο.\n"
            . "     Δεν αντιγράφεται από εδώ: αντίγραψέ τον με εργαλείο που ξέρει να\n"
            . "     συνεχίζει, και κράτα το dump που μόλις βγήκε ως έχει."
        );
    }

    $target = $dump . DIRECTORY_SEPARATOR . "crm-{$stamp}-uploads";
    $prefix = rtrim((string) realpath($uploadsSource), '/\\');
    $copied = 0;

    /** @var SplFileInfo $item */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsSource, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $item) {
        $relative = substr((string) $item->getRealPath(), strlen($prefix));
        $destination = $target . $relative;

        if ($item->isDir()) {
            if (! is_dir($destination) && ! mkdir($destination, 0700, true) && ! is_dir($destination)) {
                $fail("Δεν δημιουργήθηκε ο φάκελος: {$destination}");
            }

            continue;
        }

        $parent = dirname($destination);

        if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
            $fail("Δεν δημιουργήθηκε ο φάκελος: {$parent}");
        }

        if (! copy((string) $item->getRealPath(), $destination)) {
            $fail("Δεν αντιγράφηκε το αρχείο: {$item->getRealPath()}");
        }

        $copied++;
    }

    if ($copied !== $files) {
        $fail("Αντιγράφηκαν {$copied} από {$files} αρχεία. Το αντίγραφο είναι ελλιπές.");
    }

    $uploadsReport = ['included' => true, 'files' => $copied, 'bytes' => $bytes];

    echo "  [ΟΚ]  Έγγραφα     → {$target}\n";
}

// --- 3. Το manifest ----------------------------------------------------------

global $wpdb;

$count = static function (string $table) use ($wpdb): int {
    $name = Tables::name($table);

    // phpcs:ignore WordPress.DB.PreparedSQL
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$name}`");
};

$payload = [
    'created_at'         => current_time('c'),
    'site'               => home_url(),
    'key_fingerprint'    => $fingerprint->current(),
    'fingerprint_stored' => $fingerprint->isRecorded(),
    'encryption_enabled' => CustomerFields::isEnabled(),
    'dump_file'          => basename($dumpFile),
    'dump_bytes'         => (int) filesize($dumpFile),
    'dump_sha256'        => hash_file('sha256', $dumpFile),
    'salts_file'         => basename($saltsFile),
    'uploads'            => $uploadsReport,
    'rows'               => [
        'customers' => $count(Tables::CUSTOMERS),
        'contracts' => $count(Tables::CONTRACTS),
        'files'     => $count(Tables::FILES),
    ],
];

file_put_contents($manifest, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "  [ΟΚ]  Manifest     → {$manifest}\n";

// Ό,τι χρειάζεται η οθόνη Υγεία για να δείξει «τελευταίο αντίγραφο: πότε,
// με ποιο κλειδί» -- ΧΩΡΙΣ διαδρομή δίσκου. Ξεχωριστό από το manifest πάνω:
// αυτό ζει στο site, το manifest ζει εκτός site μαζί με το dump.
BackupState::record($payload);

// --- Σύνοψη ------------------------------------------------------------------

echo "\n";
// Τα κενά γραμμένα με το χέρι: το printf γεμίζει κατά BYTES, και μια ελληνική
// ετικέτα είναι ~2 bytes ανά χαρακτήρα, οπότε το %-14s τελειώνει πριν από την
// ετικέτα και δεν στοιχίζεται τίποτα. Το ίδιο λάθος έγινε στο
// preflight-encryption.php την ίδια μέρα και διορθώθηκε εκεί πρώτα.
printf("  Πελάτες                %s\n", $payload['rows']['customers']);
printf("  Συμβάσεις              %s\n", $payload['rows']['contracts']);
printf("  Εγγραφές αρχείων (ΒΔ)  %s\n", $payload['rows']['files']);
printf("  Μέγεθος βάσης          %s\n", number_format($payload['dump_bytes'] / 1024, 1) . ' KB');

// Τα δύο πλήθη αρχείων μετρούν διαφορετικά πράγματα και συχνά διαφωνούν: η ΒΔ
// ξέρει όσα έγγραφα κατέγραψε το CRM, ο δίσκος κρατά ΚΑΙ τα μεγέθη εικόνων του
// WordPress ΚΑΙ ό,τι έμεινε πίσω από διαγραφές. Η διαφορά είναι πληροφορία, όχι
// σφάλμα — γι' αυτό τυπώνονται δίπλα δίπλα με ετικέτες που το λένε.
printf(
    "  Αρχεία στον δίσκο      %s\n",
    $uploadsReport['included']
        ? $uploadsReport['files'] . ' (' . number_format($uploadsReport['bytes'] / 1048576, 1) . ' MB)'
        : 'ΔΕΝ ΣΥΜΠΕΡΙΛΗΦΘΗΚΑΝ (--with-uploads)'
);

echo "\n" . str_repeat('─', 64) . "\n";

if (! $payload['encryption_enabled']) {
    echo "ΠΡΟΣΟΧΗ: το ECRM_ENCRYPT_PII είναι κλειστό — το dump περιέχει ΑΦΜ και\n";
    echo "ΑΔΤ σε καθαρό κείμενο. Φύλαξέ το ανάλογα.\n\n";
    exit(0);
}

echo "Έτοιμο. Το dump και τα salts είναι ΕΝΑ πράγμα: χωρίς τα δεύτερα το\n";
echo "πρώτο δεν ανοίγει ποτέ. Επαλήθευση πριν βασιστείς σε αυτό:\n\n";
echo "  php tools/backup.php --verify " . $manifest . "\n\n";

exit(0);
