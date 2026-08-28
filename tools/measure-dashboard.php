<?php
/**
 * Πόσα ερωτήματα και πόσο χρόνο κοστίζει ΤΩΡΑ ένα άνοιγμα του dashboard.
 *
 * ## Γιατί υπάρχει
 *
 * Στις 28/08/2026 προτάθηκε να ενωθούν τα επτά ερωτήματα του
 * `DashboardRepository::cards()` σε ένα, με το επιχείρημα «280 συνδέσεις εκεί
 * που φτάνουν 40» — γραμμένο **χωρίς μέτρηση**. Δεν υπήρχε καμία υποδομή στο
 * project για να μετρηθεί κόστος ερωτήματος, οπότε η πρόταση αποσύρθηκε πριν
 * τον κώδικα (§1.11: πρόταση χωρίς μέτρηση είναι άγνωστη, όχι «πιθανώς
 * σωστή»). Αυτό το αρχείο είναι η υποδομή που έλειπε.
 *
 * Μετρά με το `$wpdb->num_queries`, που το WordPress αυξάνει σε ΚΑΘΕ
 * ερώτημα ανεξάρτητα από το `SAVEQUERIES` — δεν χρειάζεται να αγγιχτεί το
 * `wp-config.php` (§1 σημείο 10 του HANDOVER: ποτέ μην το τυπώσεις, και εδώ
 * δεν χρειάζεται καν να το επεξεργαστείς).
 *
 * Καλεί την `DashboardRepository` απευθείας, όχι μέσω REST — γιατί το
 * ζητούμενο είναι το κόστος ΤΗΣ ΒΑΣΗΣ ανά μέθοδο, και ένα REST round-trip θα
 * πρόσθετε nonce/auth/JSON overhead που δεν είναι το ερώτημα.
 *
 *     wp eval-file wp-content/plugins/energy-crm/tools/measure-dashboard.php
 *
 * Δεν γράφει τίποτα στη βάση. Δεν τυπώνει ΑΦΜ, όνομα ή διεύθυνση — μόνο
 * πλήθη, χρόνους και το ίδιο το partner_user_id (δεν είναι προσωπικό
 * δεδομένο πελάτη).
 *
 * @package EnergyCRM
 */

if (! defined('ABSPATH')) {
    echo "Τρέξε το μέσω wp-cli: wp eval-file <διαδρομή>\n";
    return;
}

@ini_set('display_errors', '1'); // phpcs:ignore
error_reporting(E_ALL);

try {
    global $wpdb;

    /**
     * Ο partner με τις περισσότερες συμβάσεις -- το dashboard ενός αδειανού
     * χρήστη δεν λέει τίποτα για το πραγματικό κόστος. §1 σημείο 6 του
     * HANDOVER: ~2.000 ανοιχτές καρτέλες συνολικά, οπότε ο πολυπληθέστερος
     * partner είναι το πιο ρεαλιστικό «χειρότερη περίπτωση» χωρίς να χρειαστεί
     * συνθετικά δεδομένα.
     */
    $contracts = $wpdb->prefix . 'ecrm_contracts';

    $userId = $wpdb->get_var(
        "SELECT partner_user_id FROM `{$contracts}` " // phpcs:ignore
        . 'GROUP BY partner_user_id ORDER BY COUNT(*) DESC LIMIT 1'
    );

    if (! $userId) {
        echo "Δεν βρέθηκε καμία σύμβαση -- δεν υπάρχει partner να μετρηθεί.\n";

        return;
    }

    $userId = (int) $userId;

    $contractCount = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$contracts}` WHERE partner_user_id = %d", // phpcs:ignore
            $userId
        )
    );

    echo "Partner user_id {$userId}, {$contractCount} συμβάσεις.\n\n";

    $repo = new EnergyCRM\Persistence\DashboardRepository();

    // Ίδιος υπολογισμός με το DashboardController::index() -- ΕΠΙΤΗΔΕΣ
    // αντιγραμμένος αντί για δικός μου. Το project έχει ξαναδιορθώσει το ίδιο
    // λάθος ζώνης ώρας τρεις φορές (HANDOVER §1, σημείο 11): ένα εργαλείο
    // μέτρησης που υπολογίζει τα όρια αλλιώς από την ΠΡΑΓΜΑΤΙΚΗ διαδρομή θα
    // μετρούσε διαφορετικό ερώτημα από αυτό που τρέχει στην παραγωγή.
    $year  = (int) current_time('Y');
    $month = (int) current_time('n');
    $today = current_time('Y-m-d');

    $yesterday = (new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('UTC')))
        ->modify('-1 day')
        ->format('Y-m-d');

    $todayStart     = $today . ' 00:00:00';
    $yesterdayStart = $yesterday . ' 00:00:00';
    $monthStart     = sprintf('%04d-%02d-01 00:00:00', $year, $month);

    /**
     * Μία κλήση, μετρημένη. Επιστρέφει [χρόνος σε ms, πλήθος ερωτημάτων].
     *
     * @return array{0: float, 1: int}
     */
    $measure = static function (string $label, callable $call) use ($wpdb): array {
        $queriesBefore = $wpdb->num_queries;
        $start         = hrtime(true);

        $call();

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $queries   = $wpdb->num_queries - $queriesBefore;

        printf("%-24s %6.2f ms   %3d ερωτήματα\n", $label, $elapsedMs, $queries);

        return [$elapsedMs, $queries];
    };

    echo "Ανά μέθοδο, με τη σειρά που τις καλεί το DashboardController:\n\n";

    [$msCards, $qCards] = $measure(
        'cards()',
        static function () use ($repo, $userId, $todayStart, $monthStart, $yesterdayStart): void {
            $repo->cards($userId, $todayStart, $monthStart, $yesterdayStart);
        }
    );

    [$msTiles, $qTiles] = $measure(
        'tiles()',
        static function () use ($repo, $userId, $monthStart): void {
            $repo->tiles($userId, $monthStart);
        }
    );

    [$msProvider, $qProvider] = $measure(
        'byProviderSince()',
        static function () use ($repo, $userId, $monthStart): void {
            $repo->byProviderSince($userId, $monthStart);
        }
    );

    [$msMonthly, $qMonthly] = $measure(
        'monthlyTotals()',
        static function () use ($repo, $userId): void {
            $repo->monthlyTotals($userId, (int) current_time('Y'));
        }
    );

    [$msRecent, $qRecent] = $measure(
        'recentActivity()',
        static function () use ($repo, $userId): void {
            $repo->recentActivity($userId);
        }
    );

    [$msAttention, $qAttention] = $measure(
        'needsAttention()',
        static function () use ($repo, $userId): void {
            $repo->needsAttention($userId);
        }
    );

    $totalMs = $msCards + $msTiles + $msProvider + $msMonthly + $msRecent + $msAttention;
    $totalQ  = $qCards + $qTiles + $qProvider + $qMonthly + $qRecent + $qAttention;

    echo "\nΣύνολο (ό,τι φορτώνει το GET /dashboard): " . round($totalMs, 2) . " ms, {$totalQ} ερωτήματα.\n";
    echo "\ncards(): {$qCards} ερωτήματα σε " . round($msCards, 2) . " ms -- αυτό μετράει η πρόταση της ενοποίησης.\n";
    echo "tiles(): {$qTiles} ερωτήματα σε " . round($msTiles, 2) . " ms -- καλυμμένο από απόφαση ιδιοκτήτη 25/08 (ένα ανά πλακίδιο).\n";
} catch (\Throwable $e) {
    echo 'ΣΦΑΛΜΑ: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
