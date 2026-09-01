<?php
/**
 * Καθαρίζει ό,τι έφτιαξε το measure-scale80-seed.php -- όλους τους
 * συνθετικούς χρήστες (διευθυντής, προϊστάμενοι, καταστήματα) και ό,τι
 * κρέμεται από αυτούς: συμβάσεις, έγγραφα (cascade από τις συμβάσεις),
 * leads.
 *
 * ΥΠΟΧΡΕΩΤΙΚΟ να τρέξει μετά τη μέτρηση -- HANDOVER §1.7: «το site πρέπει
 * να είναι πεντακάθαρο όταν ανέβει live». Το τοπικό ΕΙΝΑΙ το μελλοντικό
 * παραγωγικό.
 *
 * Ασφαλές: βρίσκει ΜΟΝΟ χρήστες με user_meta ecrm_synth80=1 (το ίδιο
 * κλειδί που έγραψε το seed, όχι login/email pattern matching) και ΜΟΝΟ
 * γραμμές contracts/leads με partner_user_id σε αυτό το σύνολο. Κανένας
 * πραγματικός χρήστης δεν μπορεί ποτέ να έχει αυτό το meta key -- καμία
 * οθόνη του CRM δεν το γράφει.
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-scale80-cleanup.php
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
        'SELECT user_id FROM %i WHERE meta_key = %s AND meta_value = %s',
        $wpdb->usermeta,
        'ecrm_synth80',
        '1'
    ));
    $userIds = array_map('intval', $userIds);

    if ($userIds === []) {
        echo "Κανένας συνθετικός χρήστης δεν βρέθηκε -- ήδη καθαρό.\n";

        return;
    }

    echo "Βρέθηκαν " . count($userIds) . " συνθετικοί χρήστες.\n";

    $ph = implode(',', array_fill(0, count($userIds), '%d'));

    // files ΔΕΝ χρειάζεται εδώ -- files.contract_id είναι ON DELETE
    // CASCADE (βλ. docblock του tools/diagnose-orphan-documents.php), οπότε
    // η διαγραφή συμβάσεων παρακάτω παίρνει τα file rows μαζί της.
    //
    // Μόνο τρεις πίνακες έχουν καν στήλη partner_user_id (DESCRIBE, 31/08):
    // contracts, leads, payouts. Το seed script δεν γράφει ποτέ payouts --
    // μένει εδώ μόνο σαν ασφάλεια, αν αλλάξει κάτι αργότερα.
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
            // Δεν αναμένεται (και τα τρία επιβεβαιώθηκαν με DESCRIBE) --
            // ανεκτικό μοτίβο ίδιο με tools/wipe-test-data.php, δεν
            // σταματάει το script αν κάτι απρόσμενο συμβεί.
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
        'SELECT COUNT(*) FROM %i WHERE meta_key = %s AND meta_value = %s',
        $wpdb->usermeta,
        'ecrm_synth80',
        '1'
    ));

    $remainingContracts = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE code LIKE %s',
        Tables::name(Tables::CONTRACTS),
        'ECRM-SYNTH80-%'
    ));

    if ($remainingUsers === 0 && $remainingContracts === 0) {
        echo "Καθαρό: 0 συνθετικοί χρήστες, 0 συνθετικές συμβάσεις.\n";
    } else {
        echo "ΠΡΟΣΟΧΗ -- έμειναν {$remainingUsers} χρήστες, {$remainingContracts} συμβάσεις με το marker. Ξανατρέξε το script.\n";
    }
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
