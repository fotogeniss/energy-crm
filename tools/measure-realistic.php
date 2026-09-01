<?php
/**
 * Μετράει escalations() / missing_docs_for() στη διορθωμένη ιεραρχία:
 * 100 καταστήματα x 10 πωλητές = 1.100 λογαριασμοί, συμβάσεις στο όνομα
 * των πωλητών (βλ. docblock του measure-realistic-seed.php για το γιατί).
 *
 * Τρέξε ΠΡΩΤΑ το measure-realistic-seed.php (με όποιο target -- 4000 ως
 * 10000 κατά τον ιδιοκτήτη). Αυτό εδώ μόνο διαβάζει.
 *
 * Διαφορά από το measure-scale80.php: εκεί το busiest "store" ΗΤΑΝ ο ίδιος
 * ο λογαριασμός-ιδιοκτήτης της σύμβασης (partner_user_id = store id). Εδώ
 * το busiest store είναι ένας ΟΜΙΛΟΣ από 10 πωλητές -- το missing_docs_for()
 * κτλ καλούνται με τη ΛΙΣΤΑ των 10 seller id, όπως θα έκανε πραγματικά ένα
 * κατάστημα που βλέπει το dashboard της δικής του ομάδας. Αυτό ασκεί το
 * σκέλος "πολλαπλά partner_user_id σε ένα IN()" που το measure-scale80.php
 * δεν άγγιζε καθόλου (εκεί το scope ήταν πάντα ένα μοναδικό id).
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-realistic.php
 *
 * Δεν γράφει τίποτα στη βάση.
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

use EnergyCRM\Persistence\Tables;

try {
    global $wpdb;

    $storeCount = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE meta_key = %s AND meta_value = %s',
        $wpdb->usermeta,
        'ecrm_synthreal',
        'store'
    ));

    if ($storeCount === 0) {
        echo "Δεν βρέθηκε ιεραρχία -- τρέξε πρώτα:\n";
        echo "wp eval-file wp-content/plugins/energy-crm/tools/measure-realistic-seed.php [target]\n";
        return;
    }

    $contractTotal = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM %i WHERE code LIKE %s',
        Tables::name(Tables::CONTRACTS),
        'ECRM-REAL-%'
    ));

    echo "{$storeCount} καταστήματα, {$contractTotal} συνθετικές συμβάσεις στη βάση.\n\n";

    /**
     * @return array{0: float, 1: int, 2: mixed}
     */
    $measure = static function (string $label, callable $call) use ($wpdb): array {
        $queriesBefore = $wpdb->num_queries;
        $start         = hrtime(true);

        $result = $call();

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $queries   = $wpdb->num_queries - $queriesBefore;

        printf("%-30s %8.2f ms   %5d ερωτήματα\n", $label, $elapsedMs, $queries);

        return [$elapsedMs, $queries, $result];
    };

    echo "escalations() -- ΟΛΗ η εταιρεία, ένα πέρασμα:\n";
    [, , $escRows] = $measure('escalations()', static function () {
        return class_exists('ECRM_Notifications') ? ECRM_Notifications::escalations() : [];
    });

    $totalEscalatedRows = 0;
    foreach ($escRows as $managerRows) {
        $totalEscalatedRows += count($managerRows);
    }
    echo "  -> " . count($escRows) . " καταστήματα έχουν έστω μία κλιμάκωση, {$totalEscalatedRows} γραμμές συνολικά.\n\n";

    // Busiest store = ο ιδιοκτήτης-κατάστημα με τις περισσότερες συμβάσεις
    // αθροισμένες στους 10 πωλητές του.
    $contracts = Tables::name(Tables::CONTRACTS);
    $storeId   = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT parent.meta_value FROM `{$contracts}` c " // phpcs:ignore
        . 'INNER JOIN %i seller ON seller.user_id = c.partner_user_id '
        . "AND seller.meta_key = 'ecrm_synthreal' AND seller.meta_value = 'seller' "
        . 'INNER JOIN %i parent ON parent.user_id = c.partner_user_id AND parent.meta_key = %s '
        . 'GROUP BY parent.meta_value ORDER BY COUNT(*) DESC LIMIT 1',
        $wpdb->usermeta,
        $wpdb->usermeta,
        'ecrm_parent'
    ));

    if (! $storeId) {
        echo "Δεν βρέθηκε συνθετικό κατάστημα με συμβάσεις -- σταματώ εδώ.\n";
        return;
    }

    $sellerIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
        'SELECT user_id FROM %i WHERE meta_key = %s AND meta_value = %d',
        $wpdb->usermeta,
        'ecrm_parent',
        $storeId
    )));

    $ph = implode(',', array_fill(0, count($sellerIds), '%d'));
    // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    $storeContractCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$contracts}` WHERE partner_user_id IN ({$ph})",
        $sellerIds
    ));

    echo "Πιο φορτωμένο κατάστημα: store_user_id {$storeId}, "
        . count($sellerIds) . " πωλητές, {$storeContractCount} συμβάσεις συνολικά.\n\n";

    echo "Ανά μέθοδο, scope = οι " . count($sellerIds) . " πωλητές αυτού του καταστήματος:\n\n";

    $measure('missing_docs_for()', static function () use ($sellerIds) {
        return class_exists('ECRM_Notifications') ? ECRM_Notifications::missing_docs_for($sellerIds) : [];
    });

    $measure('expired_docs_for()', static function () use ($sellerIds) {
        return class_exists('ECRM_Notifications') ? ECRM_Notifications::expired_docs_for($sellerIds) : [];
    });

    $measure('overdue_leads_for()', static function () use ($sellerIds) {
        return class_exists('ECRM_Notifications') ? ECRM_Notifications::overdue_leads_for($sellerIds) : [];
    });

    // needsAttention() είναι ατομικό (int $userId, όχι λίστα) -- η δική του
    // κλίμακα δοκιμής είναι "ο πιο φορτωμένος ΠΩΛΗΤΗΣ", όχι το κατάστημα.
    $busiestSellerId = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT partner_user_id FROM `{$contracts}` " // phpcs:ignore
        . "WHERE partner_user_id IN ({$ph}) " // phpcs:ignore
        . 'GROUP BY partner_user_id ORDER BY COUNT(*) DESC LIMIT 1',
        $sellerIds
    ));

    $repo = new EnergyCRM\Persistence\DashboardRepository();
    $measure('needsAttention() [unchanged]', static function () use ($repo, $busiestSellerId) {
        return $repo->needsAttention($busiestSellerId);
    });

    echo "\nΘυμήσου να τρέξεις μετά:\n";
    echo "wp eval-file wp-content/plugins/energy-crm/tools/measure-realistic-cleanup.php\n";
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
