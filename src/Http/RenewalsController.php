<?php

/**
 * GET  /renewals            contracts approaching the end of their term
 * POST /contracts/{id}/renew  start the next term as a fresh draft
 *
 * A renewal is a new contract, not an edit of the old one. The expiring supply
 * keeps its own history and its own commission; the new draft carries the same
 * customer and meter forward and starts the pipeline again.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Contract\ContractTerm;
use EnergyCRM\Domain\Contract\ExtraFields;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\EventRepository;
use WP_REST_Request;
use WP_REST_Response;

final class RenewalsController implements Controller
{
    /**
     * Carried over from the expiring contract.
     *
     * Deliberately not copied: status, code, dates, and every signature and
     * consent column. The customer has not agreed to the new term yet, and
     * inheriting a signature would mean the system believed they had.
     */
    private const CARRIED_FORWARD = [
        'customer_id',
        'provider_id',
        'program_id',
        'energy_type',
        'category',
        'price_type',
        'customer_type',
        'supply_number',
        'meter_number',
        'invoice_code',
        'extra_json',
    ];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly ContractQueries $queries,
        private readonly EventRepository $events,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/renewals', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'scope' => [
                    'type'    => 'string',
                    'default' => 'own',
                    'enum'    => ['own', 'team'],
                ],
                'days' => [
                    'type'    => 'integer',
                    'default' => 60,
                    'minimum' => 1,
                    'maximum' => 365,
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/renew', [
            'methods'             => 'POST',
            'callback'            => [$this, 'renew'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        if ($request['scope'] !== 'team') {
            $scope = $scope->toSelfOnly();
        }

        return new WP_REST_Response([
            'ok'   => true,
            'days' => (int) $request['days'],
            'rows' => $this->queries->expiring($scope, (int) $request['days']),
        ], 200);
    }

    public function renew(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        $source = $this->contracts->find((int) $request['id'], $scope);

        if ($source === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $draft = $this->carriedForward($source);

        $months = (int) ($source['term_months'] ?? 0);
        $start  = $this->nextTermStart((string) ($source['end_date'] ?? ''));

        $draft['activation_type'] = 'renewal';
        $draft['status']          = 'draft';
        $draft['start_date']      = $start;
        $draft['term_months']     = $months > 0 ? $months : null;
        $draft['end_date']        = ContractTerm::endDate($start, $months);
        $draft['notes']           = 'Ανανέωση από ' . (string) ($source['code'] ?? '');

        // 2026-08-24: carriedForward() αντιγράφει το extra_json ατόφιο από τη
        // ληγμένη σύμβαση — άρα μια ανανέωση κληρονομούσε ό,τι request_type
        // είχε η ΑΡΧΙΚΗ αίτηση (σχεδόν ποτέ 'renewal' το ίδιο). Το
        // MobilePaperwork::connectionTicks() διαβάζει ακριβώς αυτή την τιμή
        // για να αποφασίσει ποιο κουτί ΕΙΔΟΣ ΣΥΝΔΕΣΗΣ θα τσεκάρει στο
        // τυπωμένο έντυπο Orizon — ένα ανέγγιχτο αντίγραφο θα τσέκαρε
        // «Νέα Σύνδεση» (ή ό,τι ήταν το αρχικό) σε ένα έγγραφο που στην
        // πραγματικότητα είναι ανανέωση. Ίδια οικογένεια σφάλματος με το
        // (110) — άλλη διαδρομή κώδικα, δεν περνά ποτέ από το
        // ContractSaveMapping::contractFrom() που έχει εκείνη τη διόρθωση.
        if ((string) ($draft['energy_type'] ?? '') === 'mobile') {
            $extra                  = (array) json_decode((string) ($source['extra_json'] ?? ''), true);
            $extra['request_type']  = 'renewal';
            $draft['extra_json']    = ExtraFields::toJson($extra);
        }

        // create() assigns ownership from the scope: whoever renews owns the
        // new term, which is what pays them the renewal commission.
        $contractId = $this->contracts->create($draft, $scope);

        if ($contractId <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Η ανανέωση απέτυχε.'], 500);
        }

        $code = $this->contracts->assignCode($contractId, $scope);

        $this->events->record($contractId, $scope->actorId(), 'created', [
            'to_status' => 'draft',
            'message'   => $draft['notes'],
        ]);

        return new WP_REST_Response([
            'ok'          => true,
            'contract_id' => $contractId,
            'code'        => $code,
            'start_date'  => $draft['start_date'],
            'end_date'    => $draft['end_date'],
        ], 200);
    }

    /**
     * The next term begins where the current one ends.
     *
     * If that date has already passed, the supply is out of contract right now
     * and the new term starts today — backdating it would put the renewal into
     * the expiring list it just came from.
     */
    private function nextTermStart(string $currentEnd): string
    {
        $today = gmdate('Y-m-d');

        if ($currentEnd === '') {
            return $today;
        }

        $ends = strtotime($currentEnd);

        return $ends !== false && $ends > time() ? $currentEnd : $today;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function carriedForward(array $source): array
    {
        $draft = [];

        foreach (self::CARRIED_FORWARD as $column) {
            $draft[$column] = $source[$column] ?? null;
        }

        return $draft;
    }
}
