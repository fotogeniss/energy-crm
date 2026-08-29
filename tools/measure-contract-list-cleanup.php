<?php
/**
 * Καθαρίζει ό,τι έφτιαξε το tools/measure-contract-list-seed.php.
 *
 * Σβήνει ΜΟΝΟ γραμμές με code LIKE 'ECRM-SYNTH-%' -- καμία πραγματική
 * σύμβαση δεν μπορεί ποτέ να έχει αυτόν τον κωδικό (ο ΕΝΑΣ κανόνας
 * παραγωγής κωδικού είναι το ContractCode::forId(), που δεν παράγει ποτέ
 * αυτό το πρόθεμα). Τρέξε το ΠΑΝΤΑ μετά τη μέτρηση, ό,τι κι αν έδειξε.
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-contract-list-cleanup.php
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

try {
    global $wpdb;

    $contracts = $wpdb->prefix . 'ecrm_contracts';

    $before = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$contracts}` WHERE code LIKE %s", // phpcs:ignore
        'ECRM-SYNTH-%'
    ));

    if ($before === 0) {
        echo "Καμία συνθετική γραμμή δεν βρέθηκε -- ήδη καθαρό.\n";

        return;
    }

    // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM `{$contracts}` WHERE code LIKE %s",
        'ECRM-SYNTH-%'
    ));

    echo "Διαγράφηκαν {$deleted} από {$before} συνθετικές συμβάσεις.\n";

    $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$contracts}`"); // phpcs:ignore
    echo "Σύνολο συμβάσεων στον πίνακα τώρα: {$remaining}\n";
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
