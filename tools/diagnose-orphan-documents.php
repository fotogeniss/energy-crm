<?php

/**
 * Αρχεία στον δίσκο που η βάση δεν ξέρει — η κατεύθυνση που δεν κοιτάζει κανείς.
 *
 * ## Γιατί δεν το πιάνει τίποτα υπάρχον
 *
 * Κάθε έλεγχος του συστήματος ξεκινά από τη βάση και πάει προς τον δίσκο. Ο
 * `PersonalDataEraser` βρίσκει τις γραμμές ενός πελάτη και σβήνει τα αρχεία
 * τους. Η εξαγωγή Άρθρου 15 απαριθμεί γραμμές. Ακόμα και η `purgeOrphans()`,
 * παρά το όνομά της, ψάχνει **γραμμές που δείχνουν σε ανύπαρκτα αρχεία**.
 *
 * Αρχείο χωρίς γραμμή είναι αόρατο σε όλα τους. Δεν διαγράφεται σε αίτημα
 * Άρθρου 17, δεν αναφέρεται σε αίτημα Άρθρου 15, και — από 2026-08-16 —
 * αντιγράφεται κανονικά στα αντίγραφα ασφαλείας. Αυτό το εργαλείο κοιτάζει από
 * τον δίσκο προς τη βάση, που είναι η μόνη κατεύθυνση που δεν υλοποιήθηκε ποτέ.
 *
 * ## Πώς προκύπτουν τέτοια αρχεία
 *
 * Το `files.contract_id` είναι `ON DELETE CASCADE`: διαγραφή σύμβασης σβήνει τη
 * **γραμμή** αμέσως, ενώ τα bytes φεύγουν μόνο αν η διαδρομή περάσει πρώτα από
 * `FileRepository::purgeForContracts()`. Κάθε διαγραφή που δεν πέρασε από εκεί
 * άφησε αρχεία χωρίς ιδιοκτήτη. Ξεχωριστά, η `replaceKind()` άφηνε ορφανά bytes
 * σε κάθε επαναϋπογραφή μέχρι να διορθωθεί.
 *
 * ## Τι ΔΕΝ κάνει
 *
 * **Δεν σβήνει τίποτα και δεν προτείνει διαγραφή.** Αρχείο χωρίς γραμμή μπορεί
 * να είναι σκουπίδι, μπορεί και να είναι το μοναδικό αντίγραφο υπογεγραμμένης
 * σύμβασης που έχασε τη γραμμή της από bug. Η διάκριση δεν γίνεται από εδώ.
 *
 * Δεν τυπώνει ονόματα αρχείων: τα ονόματα είναι `doc_<τυχαίο>.<ext>` και δεν
 * λένε τίποτα, αλλά οι καταλήξεις και οι ημερομηνίες λένε — και αρκούν.
 *
 *     php tools/diagnose-orphan-documents.php
 *
 * Επιστρέφει 1 αν βρεθεί έστω ένα ορφανό αρχείο.
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

/** Τα αρχεία που θωρακίζουν τον φάκελο — δεν είναι έγγραφα και δεν μετρούν. */
const ECRM_GUARD_FILES = ['.htaccess', 'index.php', 'web.config'];

if (! class_exists('ECRM_Files')) {
    fwrite(STDERR, "Δεν φορτώθηκε το ECRM_Files — έτρεξε το plugin;\n");
    exit(1);
}

$dir = ECRM_Files::dir();

echo "\nΟρφανά έγγραφα — " . home_url() . "\n";
echo str_repeat('─', 64) . "\n\n";
echo "  Φάκελος: {$dir}\n\n";

// --- Ο δίσκος ----------------------------------------------------------------

/** @var array<string, SplFileInfo> $onDisk κλειδί: κανονικοποιημένη διαδρομή */
$onDisk = [];
$guards = 0;

/** @var SplFileInfo $item */
foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
) as $item) {
    if (! $item->isFile()) {
        continue;
    }

    if (in_array($item->getFilename(), ECRM_GUARD_FILES, true)) {
        $guards++;
        continue;
    }

    $onDisk[(string) $item->getRealPath()] = clone $item;
}

// --- Η βάση ------------------------------------------------------------------

global $wpdb;

$filesTable = Tables::name(Tables::FILES);

// phpcs:ignore WordPress.DB.PreparedSQL
$rows = (array) $wpdb->get_results("SELECT id, path FROM `{$filesTable}`", ARRAY_A);

$referenced = [];
$dangling   = 0;

foreach ($rows as $row) {
    $path = (string) ($row['path'] ?? '');
    $real = $path !== '' ? realpath($path) : false;

    if ($real === false) {
        $dangling++;
        continue;
    }

    $referenced[$real] = true;
}

$orphans = array_diff_key($onDisk, $referenced);

// --- Η αναφορά ---------------------------------------------------------------

printf("  %-34s %5d\n", 'Έγγραφα στον δίσκο', count($onDisk));
printf("  %-34s %5d\n", 'Αρχεία θωράκισης (δεν μετρούν)', $guards);
printf("  %-34s %5d\n", 'Γραμμές στον πίνακα files', count($rows));
printf("  %-34s %5d\n", 'Γραμμές που βρίσκουν το αρχείο τους', count($referenced));

echo "\n";

printf("  %-34s %5d\n", 'ΟΡΦΑΝΑ (αρχείο χωρίς γραμμή)', count($orphans));
printf("  %-34s %5d\n", 'Κρεμασμένες γραμμές (χωρίς αρχείο)', $dangling);

if ($orphans === []) {
    echo "\n" . str_repeat('─', 64) . "\n";
    echo "Καθαρό. Κάθε έγγραφο στον δίσκο ανήκει σε γραμμή.\n\n";
    exit(0);
}

// --- Τι είναι τα ορφανά, χωρίς να τυπωθεί όνομα ------------------------------

$byExtension = [];
$oldest      = PHP_INT_MAX;
$newest      = 0;
$bytes       = 0;

/** @var SplFileInfo $file */
foreach ($orphans as $file) {
    $extension = strtolower($file->getExtension()) ?: '(χωρίς)';

    $byExtension[$extension] = ($byExtension[$extension] ?? 0) + 1;
    $bytes                  += (int) $file->getSize();
    $oldest                  = min($oldest, (int) $file->getMTime());
    $newest                  = max($newest, (int) $file->getMTime());
}

arsort($byExtension);

echo "\n  Τι είναι, κατά κατάληξη\n";
echo '  ' . str_repeat('─', 46) . "\n";

foreach ($byExtension as $extension => $count) {
    // Η κατάληξη λέει το είδος: png είναι σχεδόν πάντα υπογραφή, jpg σαρωμένο
    // έγγραφο ταυτότητας ή λογαριασμού, pdf παραγμένο έντυπο ή υπογεγραμμένη
    // σύμβαση. Κανένα από τα τρία δεν είναι σκουπίδι εξ ορισμού.
    printf("  %-12s %5d\n", $extension, $count);
}

printf("\n  %-34s %5s MB\n", 'Συνολικό μέγεθος', number_format($bytes / 1048576, 1));
printf("  %-34s %s\n", 'Παλαιότερο', wp_date('Y-m-d H:i', $oldest));
printf("  %-34s %s\n", 'Νεότερο', wp_date('Y-m-d H:i', $newest));

echo "\n" . str_repeat('─', 64) . "\n";
echo count($orphans) . " αρχεία δεν ανήκουν σε καμία γραμμή.\n\n";
echo "Σημαίνει ότι ο PersonalDataEraser ΔΕΝ τα φτάνει: δουλεύει από τις γραμμές\n";
echo "προς τα αρχεία, οπότε αίτημα διαγραφής Άρθρου 17 τα αφήνει στον δίσκο. Η\n";
echo "εξαγωγή Άρθρου 15 δεν τα αναφέρει για τον ίδιο λόγο.\n\n";
echo "ΜΗΝ τα σβήσεις μαζικά χωρίς έλεγχο. Αρχείο χωρίς γραμμή μπορεί να είναι\n";
echo "υπόλειμμα διαγραμμένης πρόχειρης σύμβασης — ή το μοναδικό αντίγραφο\n";
echo "υπογεγραμμένης σύμβασης που έχασε τη γραμμή της από bug. Οι ημερομηνίες\n";
echo "παραπάνω δείχνουν σε ποια περίοδο ανήκουν.\n\n";

exit(1);
