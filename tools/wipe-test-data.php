<?php

/**
 * Μία φορά: αδειάζει τα δοκιμαστικά δεδομένα του live (migration από το Local),
 * κρατώντας providers/programs/commission_rules/kb_entries όπως είναι.
 *
 * Χωρίς σημαία μόνο ΜΕΤΡΑΕΙ και τυπώνει τι θα σβηνόταν. Καμία εγγραφή δεν
 * αγγίζεται στο dry-run.
 *
 *     php tools/wipe-test-data.php                          (μέτρηση μόνο)
 *     php tools/wipe-test-data.php --confirm-wipe-test-data  (CLI: σβήνει όντως)
 *     wp eval-file tools/run-wipe.php confirm-wipe-test-data (eval-file: σβήνει όντως)
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

// Μένουν όπως έχουν: PROVIDERS, PROGRAMS, COMMISSION_RULES, KB_ENTRIES.
$wipe = [
    Tables::NOTIFICATIONS,
    Tables::FILES,
    Tables::SIGNATURES,
    Tables::EVENTS,
    Tables::TASKS,
    Tables::LEADS,
    Tables::PAYOUTS,
    Tables::CONTRACTS,
    Tables::CUSTOMERS,
];

global $wpdb;

// Δύο τρόποι εκτέλεσης δίνουν τη σημαία διαφορετικά: το CLI (php -r/cron) τη
// βάζει στο $argv ως "--confirm-wipe-test-data", το `wp eval-file` (που δεν
// δέχεται custom --flags) στο $args ως απλή λέξη "confirm-wipe-test-data".
$flags   = array_merge($argv ?? [], $args ?? []);
$confirm = in_array('--confirm-wipe-test-data', $flags, true)
    || in_array('confirm-wipe-test-data', $flags, true);

echo "\n" . ($confirm ? "ΕΚΤΕΛΕΣΗ — θα σβηστούν οριστικά:" : "DRY-RUN — τίποτα δεν σβήνεται, μόνο μέτρηση:") . "\n";
echo str_repeat('─', 60) . "\n";

foreach ($wipe as $table) {
    $name  = Tables::name($table);
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$name}`");
    printf("  %-16s %6d γραμμές\n", $table, $count);

    if ($confirm && $count > 0) {
        $wpdb->query("DELETE FROM `{$name}`");
        $wpdb->query("ALTER TABLE `{$name}` AUTO_INCREMENT = 1");
    } elseif ($confirm) {
        $wpdb->query("ALTER TABLE `{$name}` AUTO_INCREMENT = 1");
    }
}

if ($confirm) {
    $deletedOption = delete_option('ecrm_pii_key_fingerprint');
    echo "\n  ecrm_pii_key_fingerprint διαγράφηκε: " . ($deletedOption ? 'ναι' : 'δεν υπήρχε') . "\n";
    echo "  (Το επόμενο πραγματικό κρυπτογραφημένο save θα καταγράψει από την αρχή\n";
    echo "   το τρέχον κλειδί ως σωστό — καθαρή αφετηρία.)\n";
}

echo "\n" . str_repeat('─', 60) . "\n";
echo $confirm
    ? "Έγινε. Οι πάροχοι/προγράμματα/προμήθειες/KB έμειναν ανέγγιχτα.\n\n"
    : "Αυτό ήταν μόνο μέτρηση. Ξανατρέξε με confirm-wipe-test-data για να σβηστούν όντως.\n\n";
