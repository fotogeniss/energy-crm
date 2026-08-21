<?php

/**
 * Τα νούμερα της καρτέλας ενός συνεργάτη.
 *
 * ## Γιατί υπάρχει ξεχωριστά από το TeamActivityRepository
 *
 * Εκείνο απαντά «τι κάνει ΟΛΗ η ομάδα τώρα» — μία γραμμή ανά άτομο, με
 * `GROUP BY partner_user_id`. Αυτό απαντά «πώς τα πάει ΑΥΤΟΣ ο άνθρωπος» —
 * ένα άτομο, με μετρήσεις που δεν έχουν νόημα σε πίνακα: ποσοστό επιτυχίας με
 * τον παρονομαστή του, μέσος χρόνος με το μέγεθος δείγματος, σύγκριση με τον
 * προηγούμενο μήνα. Το να χωρέσουν και τα δύο σε ένα `GROUP BY` θα έδινε ένα
 * ερώτημα που κανένα από τα δύο δεν το θέλει ολόκληρο.
 *
 * ## Ο κανόνας που τηρείται εδώ: κάθε νούμερο λέει και τον παρονομαστή του
 *
 * Ένα «78%» χωρίς το «21 από 27» είναι ισχυρισμός, όχι μέτρηση — και με τρεις
 * συμβάσεις γίνεται «67%» που ακούγεται σαν κρίση για τον άνθρωπο. Γι' αυτό
 * κάθε μέθοδος επιστρέφει και το πλήθος πάνω στο οποίο μέτρησε. Η οθόνη
 * αποφασίζει αν αξίζει να το δείξει· η βάση δεν κρύβει ποτέ το δείγμα.
 *
 * ## Εξουσιοδότηση
 *
 * Κάθε ερώτημα παίρνει ΚΑΙ το `UserScope` ΚΑΙ το `memberId`, και τα δύο
 * μπαίνουν στο WHERE. Ο έλεγχος `$scope->includes($memberId)` γίνεται ήδη στον
 * controller· η ρήτρα εδώ είναι δεύτερη γραμμή άμυνας, ώστε ένα ξεχασμένο
 * `if` σε μελλοντικό caller να μη γίνεται διαρροή. Βλ. ScopeClause.
 *
 * Βλ. ContractRepository για τη σημείωση στις εξαιρέσεις phpcs.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;

final class PartnerCardRepository
{
    /**
     * Πόσες συμβάσεις άνοιξε ο συνεργάτης σε δύο διαδοχικά παράθυρα.
     *
     * Οι δύο αριθμοί βγαίνουν από ΕΝΑ ερώτημα με δύο αθροίσματα υπό συνθήκη,
     * όχι από δύο ταξίδια στη βάση: αλλιώς η καρτέλα ενός ανθρώπου κοστίζει
     * τέσσερα ερωτήματα εκεί που φτάνουν δύο.
     *
     * @param string $monthStart Y-m-d 00:00:00 της αρχής του τρέχοντος μήνα, ώρα site.
     * @param string $prevStart  Y-m-d 00:00:00 της αρχής του προηγούμενου μήνα.
     *
     * @return array{month: int, prev: int}
     */
    public function monthlyCounts(UserScope $scope, int $memberId, string $monthStart, string $prevStart): array
    {
        global $wpdb;

        [$clause, $params] = ScopeClause::forScope($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM( created_at >= %s )                        AS m,
                    SUM( created_at >= %s AND created_at < %s )    AS p
                 FROM %i
                 WHERE partner_user_id = %d{$clause}",
                [
                    $monthStart,
                    $prevStart,
                    $monthStart,
                    Tables::name(Tables::CONTRACTS),
                    $memberId,
                    ...$params,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return [
            'month' => (int) ($row['m'] ?? 0),
            'prev'  => (int) ($row['p'] ?? 0),
        ];
    }

    /**
     * Επιτυχία: πόσες από όσες ΕΚΛΕΙΣΑΝ κατέληξαν πληρωτέες.
     *
     * Ο παρονομαστής είναι επίτηδες «όσες έκλεισαν» και όχι «όλες»: μια
     * σύμβαση που είναι ακόμη στον αέρα δεν είναι αποτυχία, και μετρώντας την
     * ως τέτοια τιμωρείς τον άνθρωπο που μόλις κατέθεσε δέκα αιτήσεις. Κλειστές
     * είναι οι πληρωτέες (routed/active/resolved) και οι τερματικές
     * (cancelled/terminated) — δηλαδή ΚΑΙ οι δύο λίστες έρχονται από το
     * ContractStatus, δεν γράφονται ξανά εδώ.
     *
     * @param list<string> $payable  Καταστάσεις που μετράνε ως επιτυχία.
     * @param list<string> $terminal Καταστάσεις που μετράνε ως αποτυχία.
     *
     * @return array{payable: int, settled: int}
     */
    public function successCounts(UserScope $scope, int $memberId, array $payable, array $terminal): array
    {
        global $wpdb;

        $settled = [...$payable, ...$terminal];

        if ($payable === [] || $settled === []) {
            return ['payable' => 0, 'settled' => 0];
        }

        [$clause, $params] = ScopeClause::forScope($scope);
        $payableIn         = implode(',', array_fill(0, count($payable), '%s'));
        $settledIn         = implode(',', array_fill(0, count($settled), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM( status IN ({$payableIn}) ) AS ok,
                    COUNT(*)                        AS settled
                 FROM %i
                 WHERE partner_user_id = %d AND status IN ({$settledIn}){$clause}",
                [
                    ...$payable,
                    Tables::name(Tables::CONTRACTS),
                    $memberId,
                    ...$settled,
                    ...$params,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return [
            'payable' => (int) ($row['ok'] ?? 0),
            'settled' => (int) ($row['settled'] ?? 0),
        ];
    }

    /**
     * Μέσος χρόνος από την καταχώρηση ως την υπογραφή, σε μέρες.
     *
     * ΠΡΟΣΟΧΗ ΣΤΟ ΔΕΙΓΜΑ, και γι' αυτό επιστρέφεται μαζί: η στήλη `signed_at`
     * μπήκε στις 2026-08-18. Κάθε σύμβαση που υπογράφηκε ΠΡΙΝ από εκείνη τη
     * μέρα την έχει NULL και δεν μπορεί να μετρηθεί — όχι επειδή δεν
     * υπογράφηκε, αλλά επειδή κανείς δεν κατέγραφε πότε. Ένας μέσος όρος από
     * τρεις υπογραφές δεν είναι «ο χρόνος του συνεργάτη», και η οθόνη πρέπει να
     * μπορεί να το πει. Χωρίς το `sample`, ο αριθμός θα περνούσε για ιστορικό
     * ενώ περιγράφει μόνο τις τελευταίες μέρες.
     *
     * @return array{avg: float|null, sample: int}
     */
    public function daysToSign(UserScope $scope, int $memberId): array
    {
        global $wpdb;

        [$clause, $params] = ScopeClause::forScope($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    AVG( DATEDIFF( signed_at, created_at ) ) AS avg_days,
                    COUNT(*)                                 AS sample
                 FROM %i
                 WHERE partner_user_id = %d
                   AND signed_at IS NOT NULL
                   AND signed_at >= created_at{$clause}",
                [Tables::name(Tables::CONTRACTS), $memberId, ...$params]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $sample = (int) ($row['sample'] ?? 0);

        return [
            // Χωρίς δείγμα δεν υπάρχει μέσος όρος — και το null είναι η μόνη
            // ειλικρινής τιμή. Ένα 0.0 θα διαβαζόταν «υπογράφει αυθημερόν».
            'avg'    => $sample > 0 && $row['avg_days'] !== null ? round((float) $row['avg_days'], 1) : null,
            'sample' => $sample,
        ];
    }

    /**
     * Οι τελευταίες συμβάσεις του συνεργάτη, για τη λίστα της καρτέλας.
     *
     * Ίδια σύνθεση join με το CommissionRepository::payable() — πελάτης και
     * πάροχος με LEFT JOIN, ώστε μια σύμβαση χωρίς πελάτη να μη σβήνει από τη
     * λίστα αντί να φαίνεται ελλιπής.
     *
     * @return list<array<string, mixed>>
     */
    public function recentContracts(UserScope $scope, int $memberId, int $limit = 8): array
    {
        global $wpdb;

        [$clause, $params] = ScopeClause::forScope($scope, 'c');

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.energy_type, c.updated_at,
                        p.name AS provider_name,
                        cu.first_name, cu.last_name, cu.company_name
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 WHERE c.partner_user_id = %d{$clause}
                 ORDER BY c.updated_at DESC
                 LIMIT " . max(1, min(50, $limit)),
                [
                    Tables::name(Tables::CONTRACTS),
                    Tables::name(Tables::CUSTOMERS),
                    Tables::name(Tables::PROVIDERS),
                    $memberId,
                    ...$params,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }
}
