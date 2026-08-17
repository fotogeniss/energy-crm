<?php

/**
 * Βγάζει από το site τα έγγραφα που καμία γραμμή δεν διεκδικεί.
 *
 * Συνέχεια του `diagnose-orphan-documents.php`, το οποίο μετρά και δεν αγγίζει.
 * Αυτό εδώ ενεργεί — και **μετακινεί, δεν διαγράφει**.
 *
 * ## Γιατί μετακίνηση και όχι διαγραφή
 *
 * Ο πελάτης είπε «μετέφερέ τα, διέγραψέ τα, δεν με νοιάζει» και έχει δίκιο για
 * τα σημερινά δεδομένα: σε αυτή τη βάση δεν υπήρξε ποτέ αληθινός πελάτης. Η
 * μετακίνηση όμως κοστίζει το ίδιο και κρατά μια επιλογή ανοιχτή: **αρχείο
 * χωρίς γραμμή μπορεί να είναι υπόλειμμα διαγραμμένου πρόχειρου, ή το μοναδικό
 * αντίγραφο υπογεγραμμένης σύμβασης που έχασε τη γραμμή της από bug.** Η
 * διάκριση θέλει άνοιγμα των αρχείων, και δεν γίνεται από εδώ.
 *
 * Το site μένει πεντακάθαρο είτε έτσι είτε αλλιώς — κανόνας §1.7 του HANDOVER.
 * Απλώς το «αμετάκλητο» δεν προσφέρει τίποτα εδώ και αφαιρεί κάτι.
 *
 * ## Ο κανόνας που το κάνει ασφαλές
 *
 * Τα ορφανά **ξαναϋπολογίζονται τη στιγμή της μετακίνησης**, ποτέ από λίστα που
 * έφτιαξε άλλο τρέξιμο. Αρχείο που απέκτησε γραμμή στο ενδιάμεσο δεν αγγίζεται:
 * το εργαλείο δεν παίρνει είσοδο για το ΤΙ να μετακινήσει, μόνο για το ΠΟΥ.
 *
 *     php tools/purge-orphan-documents.php <φάκελος-προορισμού>
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

use EnergyCRM\Persistence\Tables;

const ECRM_GUARD_FILES = ['.htaccess', 'index.php', 'web.config'];

$fail = static function (string $message): never {
    fwrite(STDERR, "\nΣΤΟΠ: {$message}\n\n");
    exit(1);
};

$destination = $argv[1] ?? '';

if ($destination === '') {
    fwrite(STDERR, "\nΧρήση: php tools/purge-orphan-documents.php <φάκελος-προορισμού>\n");
    fwrite(STDERR, "Ο φάκελος πρέπει να είναι ΕΚΤΟΣ του site.\n\n");
    exit(1);
}

if (! is_dir($destination) && ! mkdir($destination, 0700, true) && ! is_dir($destination)) {
    $fail("Δεν δημιουργήθηκε ο φάκελος: {$destination}");
}

$target = rtrim((string) realpath($destination), '/\\');

if ($target === '') {
    $fail("Δεν επιλύεται η διαδρομή: {$destination}");
}

// Ό,τι μετακινείται μέσα στο site δεν έφυγε από το site. Και ένα .pdf με
// ταυτότητα σε διαδρομή που σερβίρει ο web server είναι χειρότερο από ορφανό.
$abspath = rtrim((string) realpath(ABSPATH), '/\\');

if ($target === $abspath || str_starts_with($target . DIRECTORY_SEPARATOR, $abspath . DIRECTORY_SEPARATOR)) {
    $fail(
        "Ο προορισμός είναι ΜΕΣΑ στο site ({$abspath}).\n"
        . "     Μετακίνηση εκεί δεν είναι απομάκρυνση. Διάλεξε διαδρομή εκτός."
    );
}

if (! class_exists('ECRM_Files')) {
    $fail('Δεν φορτώθηκε το ECRM_Files.');
}

$dir = ECRM_Files::dir();

echo "\nΑπομάκρυνση ορφανών εγγράφων — " . home_url() . "\n";
echo str_repeat('─', 64) . "\n\n";

// --- Ποια είναι ορφανά, ΤΩΡΑ -------------------------------------------------

global $wpdb;

$filesTable = Tables::name(Tables::FILES);

// phpcs:ignore WordPress.DB.PreparedSQL
$rows = (array) $wpdb->get_col("SELECT path FROM `{$filesTable}`");

$referenced = [];

foreach ($rows as $path) {
    $real = $path !== '' ? realpath((string) $path) : false;

    if ($real !== false) {
        $referenced[$real] = true;
    }
}

$orphans = [];

/** @var SplFileInfo $item */
foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
) as $item) {
    if (! $item->isFile() || in_array($item->getFilename(), ECRM_GUARD_FILES, true)) {
        continue;
    }

    $real = (string) $item->getRealPath();

    if (! isset($referenced[$real])) {
        $orphans[$real] = $item->getFilename();
    }
}

printf("  %-30s %5d\n", 'Γραμμές στον πίνακα files', count($rows));
printf("  %-30s %5d\n", 'Ορφανά προς απομάκρυνση', count($orphans));

if ($orphans === []) {
    echo "\n" . str_repeat('─', 64) . "\n";
    echo "Καθαρό ήδη. Δεν μετακινήθηκε τίποτα.\n\n";
    exit(0);
}

// --- Μετακίνηση --------------------------------------------------------------

$stamp  = current_time('Ymd-Hi');
$folder = $target . DIRECTORY_SEPARATOR . "ecrm-orphans-{$stamp}";

if (! mkdir($folder, 0700, true) && ! is_dir($folder)) {
    $fail("Δεν δημιουργήθηκε ο φάκελος: {$folder}");
}

$moved  = 0;
$bytes  = 0;
$byType = [];

foreach ($orphans as $source => $filename) {
    $size = (int) filesize($source);

    if (! rename($source, $folder . DIRECTORY_SEPARATOR . $filename)) {
        $fail(
            "Απέτυχε η μετακίνηση μετά από {$moved} αρχεία.\n"
            . "     Ο φάκελος {$folder} έχει ό,τι πρόλαβε να φύγει· τα υπόλοιπα\n"
            . "     είναι ακόμα στη θέση τους. Ξανατρέξε το αφού λυθεί η αιτία."
        );
    }

    $extension          = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) ?: '(χωρίς)';
    $byType[$extension] = ($byType[$extension] ?? 0) + 1;
    $bytes             += $size;
    $moved++;
}

arsort($byType);

echo "\n  Μετακινήθηκαν στο {$folder}\n";
echo '  ' . str_repeat('─', 46) . "\n";

foreach ($byType as $extension => $count) {
    printf("  %-12s %5d\n", $extension, $count);
}

printf("\n  Σύνολο: %d αρχεία, %s MB\n", $moved, number_format($bytes / 1048576, 1));

echo "\n" . str_repeat('─', 64) . "\n";
echo "Το site είναι καθαρό. Επιβεβαίωσε με:\n\n";
echo "  php tools/diagnose-orphan-documents.php\n\n";
echo "Τα αρχεία ΔΕΝ διαγράφηκαν. Αν σε έναν μήνα δεν έχει λείψει τίποτα,\n";
echo "σβήσε τον φάκελο μόνος σου — αυτό είναι απόφαση, όχι βήμα εργαλείου.\n\n";

exit(0);
