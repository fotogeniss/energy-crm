<?php
/**
 * Καθαρίζει ό,τι έφτιαξε το measure-deep-hierarchy.php -- τους 50
 * συνθετικούς λογαριασμούς της αλυσίδας (marker ecrm_synthdeep). Καμία
 * σύμβαση δεν φτιάχτηκε (το script μετρά μόνο το δίκτυο, όχι συμβάσεις),
 * οπότε δεν υπάρχει τι άλλο να καθαριστεί.
 *
 * ΥΠΟΧΡΕΩΤΙΚΟ να τρέξει μετά τη μέτρηση -- HANDOVER §1.7.
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-deep-hierarchy-cleanup.php
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

require_once ABSPATH . 'wp-admin/includes/user.php';

try {
    global $wpdb;

    $userIds = $wpdb->get_col($wpdb->prepare(
        'SELECT user_id FROM %i WHERE meta_key = %s',
        $wpdb->usermeta,
        'ecrm_synthdeep'
    ));
    $userIds = array_map('intval', $userIds);

    if ($userIds === []) {
        echo "Κανένας συνθετικός χρήστης (ecrm_synthdeep) δεν βρέθηκε -- ήδη καθαρό.\n";
        return;
    }

    echo 'Βρέθηκαν ' . count($userIds) . " συνθετικοί χρήστες αλυσίδας.\n";

    $removed = 0;

    foreach ($userIds as $userId) {
        if (wp_delete_user($userId)) {
            $removed++;
        }
    }

    echo "Διαγράφηκαν {$removed}/" . count($userIds) . " λογαριασμοί.\n";
    echo "Το ecrm_path meta τους διαγράφεται αυτόματα από το wp_delete_user() -- τίποτα δεν μένει πίσω.\n";
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
