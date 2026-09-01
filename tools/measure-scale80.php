<?php
/**
 * Πόσα ερωτήματα και πόσο χρόνο κοστίζουν ΤΩΡΑ, στην κλίμακα 80 καταστημάτων,
 * οι τέσσερις νέες αναγνώσεις των (197)-(201).
 *
 * Τρέξε ΠΡΩΤΑ το measure-scale80-seed.php. Χωρίς αυτό, οι αριθμοί εδώ κάτω
 * θα είναι βασισμένοι σε ό,τι υπάρχει ήδη στη βάση -- πιθανόν πολύ μικρό
 * για να δείξει τίποτα, ίδιο ρίσκο που περιγράφει το docblock του
 * measure-contract-list-seed.php.
 *
 * Το κύριο ερώτημα εδώ είναι το `ECRM_Notifications::escalations()`: ΕΝΑ
 * πέρασμα σε ΟΛΗ την εταιρεία, με ΕΝΑ `uplineOf()` (άρα `get_user_meta`) ανά
 * αδρανή σύμβαση -- ρητά τεκμηριωμένο σαν «αποδεκτό στη σημερινή κλίμακα,
 * πρώτο σημείο να μετρηθεί αν μεγαλώσει η βάση συνεργατών». Αυτό το script
 * είναι εκείνη η μέτρηση.
 *
 * Καλεί τις μεθόδους απευθείας, όχι μέσω REST -- ίδιος λόγος με το
 * measure-dashboard.php: το ζητούμενο είναι το κόστος ΤΗΣ ΒΑΣΗΣ, όχι
 * nonce/auth/JSON overhead.
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-scale80.php
 *
 * Δεν γράφει τίποτα στη βάση.
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

@ini_set('display_errors', '1'); // phpcs:ignore
error_reporting(E_ALL);

use EnergyCRM\Persistence\Tables;

try {
    global $wpdb;

    $storeCount = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE meta_key = %s AND meta_value = %s',
        $wpdb->usermeta,
        'ecrm_synth80',
        '1'
    ));

    if ($storeCount === 0) {
        echo "Δεν βρέθηκαν συνθετικοί χρήστες -- τρέξε πρώτα:\n";
        echo "wp eval-file wp-content/plugins/energy-crm/tools/measure-scale80-seed.php\n";

        return;
    }

    echo "{$storeCount} συνθετικοί χρήστες (διευθυντής + προϊστάμενοι + καταστήματα) στη βάση.\n\n";

    /**
     * Μία κλήση, μετρημένη. Ίδια συνάρτηση με το measure-dashboard.php.
     *
     * @return array{0: float, 1: int}
     */
    $measure = static function (string $label, callable $call) use ($wpdb): array {
        $queriesBefore = $wpdb->num_queries;
        $start         = hrtime(true);

        $result = $call();

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $queries   = $wpdb->num_queries - $queriesBefore;

        printf("%-28s %8.2f ms   %5d ερωτήματα\n", $label, $elapsedMs, $queries);

        return [$elapsedMs, $queries, $result];
    };

    // --- το company-wide escalations() -- το κύριο ερώτημα αυτού του script

    echo "escalations() -- ΟΛΗ η εταιρεία, ένα πέρασμα:\n";
    [$msEsc, $qEsc, $escRows] = $measure('escalations()', static function () {
        return class_exists('ECRM_Notifications') ? ECRM_Notifications::escalations() : [];
    });

    $totalEscalatedRows = 0;
    foreach ($escRows as $managerRows) {
        $totalEscalatedRows += count($managerRows);
    }
    $managersWithEscalations = count($escRows);

    echo "  -> {$managersWithEscalations} προϊστάμενοι έχουν έστω μία κλιμάκωση, {$totalEscalatedRows} γραμμές συνολικά.\n\n";

    // --- ο πιο φορτωμένος συνθετικός partner, ίδιο σκεπτικό με measure-dashboard.php

    $contracts = Tables::name(Tables::CONTRACTS);
    $storeId   = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT c.partner_user_id FROM `{$contracts}` c " // phpcs:ignore
        . 'INNER JOIN %i um ON um.user_id = c.partner_user_id '
        . "WHERE um.meta_key = %s AND um.meta_value = %s "
        . 'GROUP BY c.partner_user_id ORDER BY COUNT(*) DESC LIMIT 1',
        $wpdb->usermeta,
        'ecrm_synth80',
        '1'
    ));

    if (! $storeId) {
        echo "Δεν βρέθηκε συνθετικό κατάστημα με συμβάσεις -- σταματώ εδώ.\n";

        return;
    }

    $storeContractCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$contracts}` WHERE partner_user_id = %d", // phpcs:ignore
        $storeId
    ));

    echo "Πιο φορτωμένο συνθετικό κατάστημα: partner_user_id {$storeId}, {$storeContractCount} συμβάσεις.\n\n";

    echo "Ανά μέθοδο, ίδια σειρά με το DashboardController::index() (attention_extra):\n\n";

    $measure('missing_docs_for()', static function () use ($storeId) {
        return class_exists('ECRM_Notifications') ? ECRM_Notifications::missing_docs_for([$storeId]) : [];
    });

    $measure('expired_docs_for()', static function () use ($storeId) {
        return class_exists('ECRM_Notifications') ? ECRM_Notifications::expired_docs_for([$storeId]) : [];
    });

    $measure('overdue_leads_for()', static function () use ($storeId) {
        return class_exists('ECRM_Notifications') ? ECRM_Notifications::overdue_leads_for([$storeId]) : [];
    });

    $repo = new EnergyCRM\Persistence\DashboardRepository();

    $measure('needsAttention() [unchanged]', static function () use ($repo, $storeId) {
        return $repo->needsAttention($storeId);
    });

    echo "\nΘυμήσου να τρέξεις μετά:\n";
    echo "wp eval-file wp-content/plugins/energy-crm/tools/measure-scale80-cleanup.php\n";
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
