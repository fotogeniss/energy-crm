<?php
/**
 * Καθαρίζει ό,τι έφτιαξε το measure-realistic-seed.php -- όλους τους
 * συνθετικούς λογαριασμούς (marker ecrm_synthreal, τιμές store/seller) και
 * ό,τι κρέμεται από αυτούς. Ίδιο μοτίβο με measure-scale80-cleanup.php και
 * measure-stress-cleanup.php· ξεχωριστό marker, δεν αγγίζει τα άλλα δύο.
 *
 * Καθαρίζει επίσης τα Application Passwords που έφτιαξε το
 * tools/load-test-appwd.php για τους ίδιους λογαριασμούς -- αυτά χάνονται
 * ούτως ή άλλως με το wp_delete_user(), αλλά το αρχείο διαπιστευτηρίων
 * (tools/.load-test-credentials.json) ΔΕΝ διαγράφεται αυτόματα από τον
 * δίσκο· διαγράφεται εδώ ρητά ώστε να μη μείνει πίσω αρχείο με (άχρηστους
 * πλέον, αλλά ευαίσθητης μορφής) κωδικούς.
 *
 * ΥΠΟΧΡΕΩΤΙΚΟ να τρέξει μετά τη μέτρηση -- HANDOVER §1.7.
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-realistic-cleanup.php
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

require_once ABSPATH . 'wp-admin/includes/user.php';

use EnergyCRM\Persistence\Tables;

try {
    global $wpdb;

    $userIds = $wpdb->get_col($wpdb->prepare(
        'SELECT user_id FROM %i WHERE meta_key = %s',
        $wpdb->usermeta,
        'ecrm_synthreal'
    ));
    $userIds = array_map('intval', $userIds);

    if ($userIds === []) {
        echo "Κανένας συνθετικός χρήστης (ecrm_synthreal) δεν βρέθηκε -- ήδη καθαρό.\n";
    } else {
        echo "Βρέθηκαν " . count($userIds) . " συνθετικοί χρήστες.\n";

        $ph = implode(',', array_fill(0, count($userIds), '%d'));

        $tablesToWipe = [
            Tables::LEADS,
            Tables::PAYOUTS,
            Tables::CONTRACTS,
        ];

        foreach ($tablesToWipe as $table) {
            $name = Tables::name($table);

            // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$name}` WHERE partner_user_id IN ({$ph})",
                $userIds
            ));

            if ($deleted === false) {
                echo "  {$table}: παραλείπεται (" . $wpdb->last_error . ")\n";
                continue;
            }

            echo "  {$table}: {$deleted} γραμμές διαγράφηκαν.\n";
        }

        $deletedUsers = 0;
        foreach ($userIds as $userId) {
            if (wp_delete_user($userId)) {
                $deletedUsers++;
            }
        }

        echo "\nΔιαγράφηκαν {$deletedUsers} από " . count($userIds) . " συνθετικοί χρήστες.\n";
    }

    $credsFile = __DIR__ . '/.load-test-credentials.json';
    if (file_exists($credsFile)) {
        unlink($credsFile);
        echo "Διαγράφηκε το αρχείο διαπιστευτηρίων: tools/.load-test-credentials.json\n";
    }

    $remainingUsers = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE meta_key = %s',
        $wpdb->usermeta,
        'ecrm_synthreal'
    ));

    $remainingContracts = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE code LIKE %s',
        Tables::name(Tables::CONTRACTS),
        'ECRM-REAL-%'
    ));

    if ($remainingUsers === 0 && $remainingContracts === 0) {
        echo "Καθαρό: 0 συνθετικοί χρήστες, 0 συνθετικές συμβάσεις.\n";
    } else {
        echo "ΠΡΟΣΟΧΗ -- έμειναν {$remainingUsers} χρήστες, {$remainingContracts} συμβάσεις με το marker. Ξανατρέξε το script.\n";
    }
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
