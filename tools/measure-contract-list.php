<?php
/**
 * Πόσο κοστίζει ΤΩΡΑ το `ORDER BY updated_at` της λίστας συμβάσεων, ενώ το
 * composite index (partner_user_id, status, created_at) καλύπτει created_at.
 *
 * ## Γιατί υπάρχει
 *
 * EKKREMI-29-08.html §2.2: το `AddContractListIndexes` migration (CHANGELOG
 * ιστορικό) χτίστηκε ρητά για «αυτοί οι συνεργάτες, αυτή η κατάσταση, πιο
 * πρόσφατα πρώτα» -- αλλά το `ContractQueries::search()`/`quickSearch()`/
 * `possibleDuplicates()` ταξινομούν σε `updated_at`, όχι `created_at`. Η
 * πρόταση «είναι μία γραμμή, θα βοηθήσει» είναι ΥΠΟΘΕΣΗ -- ίδιο σχήμα με το
 * «280 συνδέσεις εκεί που φτάνουν 40» που έκλεισε χωρίς αλλαγή στην (169)
 * όταν μετρήθηκε. Αυτό το αρχείο είναι η μέτρηση.
 *
 * Χωρίς συνθετικά δεδομένα σκόπιμα -- πρώτα η ΠΡΑΓΜΑΤΙΚΗ χειρότερη
 * περίπτωση: HANDOVER §1 σημείο 6 λέει ~2.000 ανοιχτές καρτέλες συνολικά,
 * και η πραγματική χειρότερη περίπτωση δεν είναι ένας partner -- είναι ο
 * διαχειριστής χωρίς φίλτρο κατάστασης, όπου το ScopeClause γυρνάει άδειο
 * απόσπασμα (καμία στήλη partner_user_id στο WHERE) και η ταξινόμηση πέφτει
 * πάνω σε ολόκληρο τον πίνακα. Αν ούτε αυτό δείξει τίποτα, δεν χρειάζεται
 * συνθετικά δεδομένα για να αποδειχθεί το αντίθετο.
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-contract-list.php
 *
 * Δεν γράφει τίποτα στη βάση. Τυπώνει μόνο πλήθη, χρόνους και EXPLAIN plans
 * -- καμία στήλη προσωπικού δεδομένου.
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

@ini_set('display_errors', '1'); // phpcs:ignore
error_reporting(E_ALL);

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractQueries;

try {
    global $wpdb;

    $contracts = $wpdb->prefix . 'ecrm_contracts';

    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$contracts}`"); // phpcs:ignore
    echo "Σύνολο συμβάσεων στον πίνακα: {$total}\n\n";

    if ($total === 0) {
        echo "Άδειος πίνακας -- τίποτα να μετρηθεί.\n";

        return;
    }

    $queries = new ContractQueries();

    /**
     * Μία κλήση, μετρημένη. Επιστρέφει [χρόνος σε ms, πλήθος γραμμών].
     *
     * @return array{0: float, 1: int}
     */
    $measure = static function (string $label, callable $call): array {
        $start = hrtime(true);
        $rows  = $call();
        $ms    = (hrtime(true) - $start) / 1_000_000;

        printf("%-42s %7.3f ms   %4d γραμμές\n", $label, $ms, count($rows));

        return [$ms, count($rows)];
    };

    /** Το EXPLAIN plan μιας παραλλαγής, χωρίς να ξαναγραφτεί η σειρά αναζήτησης. */
    $explain = static function (string $where, array $params, string $orderBy) use ($wpdb, $contracts): void {
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $sql = $wpdb->prepare(
            "EXPLAIN SELECT c.id FROM `{$contracts}` c WHERE {$where} ORDER BY {$orderBy} LIMIT 200",
            $params
        );
        $row = $wpdb->get_row($sql, ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        if (! $row) {
            echo "  EXPLAIN ORDER BY {$orderBy}: (καμία γραμμή -- απροσδόκητο)\n";

            return;
        }

        printf(
            "  ORDER BY %-16s key=%-24s rows=%-6s Extra=%s\n",
            $orderBy,
            $row['key'] ?? 'NULL',
            $row['rows'] ?? '?',
            $row['Extra'] ?? ''
        );
    };

    // ---- 1. Ο διαχειριστής, χωρίς φίλτρο κατάστασης -- η πραγματική
    //         χειρότερη περίπτωση: ScopeClause δεν προσθέτει τίποτα στο
    //         WHERE, άρα η ταξινόμηση αγγίζει ό,τι δείξει το LIMIT από
    //         ολόκληρο τον πίνακα.
    $adminId = (int) $wpdb->get_var(
        "SELECT ID FROM `{$wpdb->users}` u
         INNER JOIN `{$wpdb->usermeta}` m ON m.user_id = u.ID
         WHERE m.meta_key = '{$wpdb->prefix}capabilities' AND m.meta_value LIKE '%administrator%'
         LIMIT 1"
    ); // phpcs:ignore

    echo "-- 1. Διαχειριστής, καμία κατάσταση (limit 200 προεπιλογή) --\n";

    if ($adminId > 0) {
        $scope = UserScope::forAdministrator($adminId);

        $measure('search() -- ORDER BY updated_at (τρέχον)', static function () use ($queries, $scope): array {
            return $queries->search($scope, '', '', 200);
        });

        $explain('1 = 1', [], 'c.updated_at DESC');
        $explain('1 = 1', [], 'c.created_at DESC');
    } else {
        echo "  Δεν βρέθηκε διαχειριστής -- παραλείπεται.\n";
    }

    // ---- 2. Ο πολυπληθέστερος partner, με τη συχνότερη κατάστασή του --
    //         η καθημερινή περίπτωση: ένας πωλητής ανοίγει τη λίστα του.
    echo "\n-- 2. Ο partner με τις περισσότερες συμβάσεις, φιλτραρισμένος σε status --\n";

    $userId = $wpdb->get_var(
        "SELECT partner_user_id FROM `{$contracts}` " // phpcs:ignore
        . 'GROUP BY partner_user_id ORDER BY COUNT(*) DESC LIMIT 1'
    );

    if ($userId) {
        $userId = (int) $userId;

        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM `{$contracts}` WHERE partner_user_id = %d " // phpcs:ignore
            . 'GROUP BY status ORDER BY COUNT(*) DESC LIMIT 1',
            $userId
        ));

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$contracts}` WHERE partner_user_id = %d AND status = %s", // phpcs:ignore
            $userId,
            $status
        ));

        echo "  partner_user_id {$userId}, status='{$status}', {$count} συμβάσεις σε αυτό το φίλτρο.\n";

        $scope = UserScope::forSelf($userId);

        $measure('search() -- ORDER BY updated_at (τρέχον)', static function () use ($queries, $scope, $status): array {
            return $queries->search($scope, $status, '', 200);
        });

        $explain('c.partner_user_id = %d AND c.status = %s', [$userId, $status], 'c.updated_at DESC');
        $explain('c.partner_user_id = %d AND c.status = %s', [$userId, $status], 'c.created_at DESC');
    } else {
        echo "  Δεν βρέθηκε partner -- παραλείπεται.\n";
    }

    echo "\nΔιάβασε το Extra: 'Using filesort' σημαίνει ότι το MySQL ταξινόμησε\n";
    echo "εκτός index -- αναμενόμενο και στα δύο σκέλη για updated_at, αφού το\n";
    echo "index καλύπτει μόνο created_at. Το ερώτημα δεν είναι αν υπάρχει\n";
    echo "filesort -- είναι πόσες γραμμές αγγίζει (στήλη 'rows') και πόσο ms\n";
    echo "κοστίζει παραπάνω από τη μηδενική εναλλακτική.\n";
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
