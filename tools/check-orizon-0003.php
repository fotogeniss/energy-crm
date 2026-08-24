<?php

/**
 * Διαγνωστικό, μη-καταστροφικό: τι έδειξε η σύμβαση ORIZON-0003 μετά το
 * «Αποτυχία.» στη σελίδα υπογραφής, και τι λέει το debug.log γύρω από τότε.
 *
 *     wp eval-file tools/run-check-orizon-0003.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$load = rtrim((string) $root, '/\\') . '/wp-load.php';

if (! is_readable($load)) {
    fwrite(STDERR, "Δεν βρέθηκε το wp-load.php στο {$root}\n");
    exit(1);
}

require_once $load;

global $wpdb;

$table = $wpdb->prefix . 'ecrm_contracts';
$row   = $wpdb->get_row(
    $wpdb->prepare("SELECT id, code, status, signed_at, updated_at, created_at FROM `{$table}` WHERE code = %s", 'ORIZON-0003'),
    ARRAY_A
);

echo "\n" . str_repeat('─', 60) . "\n";
if (! $row) {
    echo "Δεν βρέθηκε σύμβαση με κωδικό ORIZON-0003.\n";
} else {
    foreach ($row as $k => $v) {
        printf("  %-12s %s\n", $k, $v === null ? 'NULL' : (string) $v);
    }
}
echo str_repeat('─', 60) . "\n";

// Πρόσφατα events καταγεγραμμένα για αυτή τη σύμβαση.
if ($row) {
    $et = $wpdb->prefix . 'ecrm_events';
    $ev = $wpdb->get_results(
        $wpdb->prepare("SELECT created_at, type, message FROM `{$et}` WHERE contract_id = %d ORDER BY id DESC LIMIT 10", (int) $row['id']),
        ARRAY_A
    );
    echo "\nΤελευταία events:\n";
    if (! $ev) {
        echo "  (κανένα)\n";
    } else {
        foreach ($ev as $e) {
            printf("  %s  %-10s %s\n", $e['created_at'], $e['type'], mb_substr((string) $e['message'], 0, 120));
        }
    }
}

// Τελευταίες γραμμές του debug.log, αν υπάρχει.
$log = rtrim((string) $root, '/\\') . '/wp-content/debug.log';
echo "\n" . str_repeat('─', 60) . "\n";
if (! is_readable($log)) {
    echo "Δεν βρέθηκε αναγνώσιμο wp-content/debug.log (WP_DEBUG_LOG ίσως κλειστό).\n";
} else {
    $size = filesize($log);
    $fh   = fopen($log, 'rb');
    if ($fh) {
        $chunk = 20000;
        fseek($fh, max(0, $size - $chunk));
        $tail = stream_get_contents($fh);
        fclose($fh);
        echo "Τελευταίες ~{$chunk} bytes του debug.log:\n\n";
        echo $tail . "\n";
    }
}
echo str_repeat('─', 60) . "\n\n";
