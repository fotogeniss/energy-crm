<?php

/**
 * GET /commissions — what a partner has earned, and what is still in flight.
 *
 * The numbers here are what people get paid on, so nothing is invented: the
 * amounts come from ECRM_Commissions, which owns the rules, and this only
 * fetches, sums and labels.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Commissions;
use ECRM_DB;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Commission\CommissionAmount;
use EnergyCRM\Domain\Commission\MonthlyTotals;
use EnergyCRM\Persistence\CommissionRepository;
use WP_REST_Request;
use WP_REST_Response;

final class CommissionsController implements Controller
{
    /** Not yet payable, but on the way there. */
    private const IN_PROGRESS = ['new', 'processing', 'pending_signature', 'pending'];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly CommissionRepository $commissions,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/commissions', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::needs(Capability::VIEW_COMMISSIONS),
            'args'                => [
                'scope' => ['type' => 'string', 'default' => 'own', 'enum' => ['own', 'team']],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();

        if ($request['scope'] !== 'team') {
            $scope = $scope->toSelfOnly();
        }

        $rows    = [];
        $entries = [];
        $total   = 0.0;
        $paid    = 0.0;
        $unpaid  = 0.0;

        // AUDIT 30/08: το `$total`/`$paid`/`$unpaid` αθροίζαν το ΑΣΤΡΟΓΓΥΛΕΥΤΟ
        // `$amount` και στρογγύλευαν μία φορά στο τέλος, ενώ κάθε γραμμή στο
        // `$rows[]` έδειχνε το ατομικά στρογγυλεμένο ποσό (`round($amount, 2)`
        // εκεί, πριν τη διόρθωση). Το ίδιο το `admin/class-ecrm-payouts.php::
        // create()` το λέει ρητά στο δικό του docblock: «το σύνολο είναι το
        // άθροισμα των στρογγυλεμένων γραμμών, όχι η στρογγυλεμένη ολότητα»
        // -- ακριβώς επειδή αλλιώς το εμφανιζόμενο σύνολο μπορεί να διαφωνήσει
        // κατά ένα λεπτό με το άθροισμα των γραμμών που βλέπει ο συνεργάτης
        // στην ίδια οθόνη. Εδώ δεν εφαρμοζόταν αυτή η αρχή -- το `$amount`
        // στρογγυλεύεται τώρα ΜΙΑ φορά, αμέσως μετά τον υπολογισμό, και η
        // ίδια στρογγυλεμένη τιμή τροφοδοτεί και τη γραμμή και το άθροισμα.
        foreach ($this->commissions->payable($scope, ECRM_DB::payable_statuses()) as $row) {
            $amount   = round(CommissionAmount::of($row, [ECRM_Commissions::class, 'amount_for']), 2);
            $isPaid   = ($row['payout_status'] ?? '') === 'paid';
            $customer = $row['company_name']
                ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

            $total += $amount;
            $isPaid ? $paid += $amount : $unpaid += $amount;

            $rows[] = [
                'code'     => $row['code'],
                'customer' => $customer !== '' ? $customer : '—',
                'provider' => $row['provider_name'] ?: '—',
                'amount'   => $amount,
                'paid'     => $isPaid,
            ];

            $entries[] = [
                'month'  => substr((string) $row['updated_at'], 0, 7),
                'amount' => $amount,
                // Υπολογιζόταν ήδη τρεις γραμμές πιο πάνω και πεταγόταν: η
                // οθόνη έδειχνε σταθερό badge «Καταχωρημένο» σε κάθε μήνα.
                'paid'   => $isPaid,
            ];
        }

        $expected = 0.0;

        // Ζωντανός υπολογισμός, σωστά: τίποτα από αυτά δεν έχει μπει σε
        // παρτίδα, άρα δεν υπάρχει στιγμιότυπο να σεβαστούμε. Ίδια διόρθωση
        // εδώ: στρογγυλεύεται πριν την πρόσθεση, όχι μετά.
        foreach ($this->commissions->inProgress($scope, self::IN_PROGRESS) as $row) {
            $expected += round((float) ECRM_Commissions::amount_for($row), 2);
        }

        $monthly = MonthlyTotals::from($entries);

        // Το LIMIT της payable() δεν φαινόταν πουθενά: πάνω από αυτό, το σύνολο
        // ευρώ έβγαινε μικρότερο χωρίς καμία ένδειξη. Τώρα η οθόνη ξέρει ότι
        // κοιτάζει μέρος.
        $available = $this->commissions->countPayable($scope, ECRM_DB::payable_statuses());

        return new WP_REST_Response([
            'ok'           => true,
            'rows'         => $rows,
            'available'    => $available,
            'truncated'    => $available > count($rows),
            'total'        => round($total, 2),
            'paid_total'   => round($paid, 2),
            'unpaid_total' => round($unpaid, 2),
            'count'        => count($rows),
            'months'       => $monthly['months'],
            'best'         => $monthly['best'],
            'best_label'   => $monthly['best_label'],
            'pending_est'  => round($expected, 2),
        ], 200);
    }
}
