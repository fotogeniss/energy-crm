<?php

/**
 * GET /payouts              the caller's own settlement batches (own/team)
 * GET /payouts/{id}/statement  the PDF certificate for one batch
 *
 * Build queue 11. Ξεχωριστή διαδρομή από το wp-admin `ECRM_Payouts::pdf()`
 * (admins, `admin_post`, id από `$_GET`) επίτηδες: εκείνος ο χειριστής δεν
 * ελέγχει ποιανού είναι η παρτίδα — ρωτάει μόνο «είσαι διαχειριστής;» — και
 * αρκεί για τον σκοπό του, γιατί κάθε διαχειριστής βλέπει ούτως ή άλλως όλες.
 * Αν ο ίδιος χειριστής χαλάρωνε σε `VIEW_COMMISSIONS`, κάθε συνεργάτης θα
 * κατέβαζε τη βεβαίωση οποιουδήποτε άλλου αλλάζοντας το `id` στο URL — το
 * `$_GET['id']` δεν περνάει ποτέ από `UserScope`.
 *
 * Εδώ ο έλεγχος είναι στην ίδια τη γραμμή: `$scope->includes($payout['partner_user_id'])`
 * πριν χτιστεί οποιοδήποτε byte PDF, όχι μετά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_PDF;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Infrastructure\PdfRender;
use EnergyCRM\Persistence\PayoutRepository;
use WP_REST_Request;
use WP_REST_Response;

final class PayoutsController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly PayoutRepository $payouts,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/payouts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::needs(Capability::VIEW_COMMISSIONS),
            'args'                => [
                'scope' => ['type' => 'string', 'default' => 'own', 'enum' => ['own', 'team']],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/payouts/(?P<id>\d+)/statement', [
            'methods'             => 'GET',
            'callback'            => [$this, 'statement'],
            'permission_callback' => Guards::needs(Capability::VIEW_COMMISSIONS),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();

        if ($request['scope'] !== 'team') {
            $scope = $scope->toSelfOnly();
        }

        $rows = [];

        foreach ($this->payouts->forScope($scope) as $b) {
            $u = get_userdata((int) $b['partner_user_id']);

            $rows[] = [
                'id'      => (int) $b['id'],
                'partner' => $u ? $u->display_name : ('#' . $b['partner_user_id']),
                'period'  => $b['period'] ?: '—',
                'count'   => (int) $b['cnt'],
                'amount'  => round((float) $b['amount'], 2),
                'status'  => $b['status'],
                'paid_at' => $b['paid_at'],
            ];
        }

        return new WP_REST_Response(['ok' => true, 'rows' => $rows], 200);
    }

    public function statement(WP_REST_Request $request): WP_REST_Response
    {
        $id     = (int) $request['id'];
        $payout = $this->payouts->find($id);

        // Ίδιο μήνυμα με «δεν βρέθηκε» — η ύπαρξη μιας παρτίδας άλλου
        // συνεργάτη δεν είναι πληροφορία που δίνουμε σε κανέναν να την
        // ψάξει δοκιμάζοντας id.
        if ($payout === null || ! $this->scopes->forCurrentUser()->includes((int) $payout['partner_user_id'])) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
        }

        $lines = $this->payouts->statementLines($id);
        $u     = get_userdata((int) $payout['partner_user_id']);

        $bytes = PdfRender::bytes(static fn (): string => ECRM_PDF::build_statement([
            'partner'   => $u ? $u->display_name : ('#' . $payout['partner_user_id']),
            'period'    => $payout['period'],
            'status'    => $payout['status'],
            'paid_at'   => $payout['paid_at'],
            'payout_id' => $id,
        ], $lines));

        if ($bytes === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Σφάλμα δημιουργίας PDF.'], 500);
        }

        return new WP_REST_Response([
            'ok'       => true,
            'filename' => 'ekkatharisi-' . $id . '.pdf',
            'mime'     => 'application/pdf',
            'b64'      => base64_encode($bytes),
        ], 200);
    }
}
