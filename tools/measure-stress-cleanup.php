<?php
/**
 * Καθαρίζει ό,τι έφτιαξε το measure-stress.php -- όλους τους συνθετικούς
 * χρήστες (marker ecrm_synthstress, οποιαδήποτε τιμή: director/manager/
 * store) και ό,τι κρέμεται από αυτούς.
 *
 * ΥΠΟΧΡΕΩΤΙΚΟ να τρέξει μετά -- ίδιος λόγος με το measure-scale80-cleanup.php
 * (HANDOVER §1.7, «πεντακάθαρο όταν ανέβει live»). Μπορεί να πάρει λίγα
 * λεπτά στην υψηλότερη κλίμακα (ως 20.000 συμβάσεις).
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-stress-cleanup.php
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
        'ecrm_synthstress'
    ));
    $userIds = array_map('intval', $userIds);

    if ($userIds === []) {
        echo "Κανένας συνθετικός χρήστης (ecrm_synthstress) δεν βρέθηκε -- ήδη καθαρό.\n";

        return;
    }

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

    $remainingUsers = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE meta_key = %s',
        $wpdb->usermeta,
        'ecrm_synthstress'
    ));

    $remainingContracts = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE code LIKE %s',
        Tables::name(Tables::CONTRACTS),
        'ECRM-STRESS-%'
    ));

    if ($remainingUsers === 0 && $remainingContracts === 0) {
        echo "Καθαρό: 0 συνθετικοί χρήστες, 0 συνθετικές συμβάσεις.\n";
    } else {
        echo "ΠΡΟΣΟΧΗ -- έμειναν {$remainingUsers} χρήστες, {$remainingContracts} συμβάσεις με το marker. Ξανατρέξε το script.\n";
    }
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
