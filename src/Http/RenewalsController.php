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
use EnergyCRM\Access\ProviderVisibility;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractTerm;
use EnergyCRM\Domain\Contract\ExtraFields;
use EnergyCRM\Infrastructure\RenewalOffers;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\ProviderRepository;
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
        private readonly ProviderVisibility $providerAccess,
        private readonly ?ProviderRepository $providers = null,
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
            'args'                => [
                'id'         => ['type' => 'integer', 'required' => true],
                // (335) «Ανανέωση με αυτό»: το πρόγραμμα της πρότασης.
                'program_id' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
            ],
        ]);

        // (335) Η ετήσια κατανάλωση, γραμμένη από τη λίστα «Λήξεις».
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/annual-kwh', [
            'methods'             => 'POST',
            'callback'            => [$this, 'annualKwh'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'  => ['type' => 'integer', 'required' => true],
                'kwh' => ['type' => 'number', 'required' => true, 'minimum' => 1, 'maximum' => 10000000],
            ],
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
            'rows' => $this->withOffers($this->queries->expiring($scope, (int) $request['days']), $scope),
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

        // Η ανανέωση είναι ΝΕΑ αίτηση στον ίδιο πάροχο (278): αν ο δράστης δεν
        // τον βλέπει πια, δεν την ανοίγει. Η παλιά σύμβαση μένει όπως ήταν.
        $providerId = (int) ($source['provider_id'] ?? 0);

        if ($providerId > 0 && ! $this->providerAccess->forScope($scope)->allows($providerId)) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Δεν έχεις πλέον πρόσβαση στον πάροχο αυτής της σύμβασης, '
                    . 'οπότε δεν μπορείς να την ανανεώσεις. Ζήτα από τον υπεύθυνό σου.',
            ], 403);
        }

        $draft = $this->carriedForward($source);

        // (335) «Ανανέωση με αυτό»: άλλο πρόγραμμα, ίδιας ενέργειας, ενεργό, από
        // πάροχο που βλέπει ο δράστης. Οτιδήποτε άλλο αγνοείται και η ανανέωση
        // μένει στο πρόγραμμα που είχε -- ποτέ μισή αλλαγή.
        $offered = $this->offeredProgram((int) $request['program_id'], $source, $scope);

        if ($offered !== null) {
            $draft['provider_id'] = (int) $offered['provider_id'];
            $draft['program_id']  = (int) $offered['id'];
            $draft['price_type']  = $offered['price_type'] ?: ($draft['price_type'] ?? null);
        }

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

    public function annualKwh(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        $id       = (int) $request['id'];
        $contract = $this->contracts->find($id, $scope);

        if ($contract === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $extra               = (array) json_decode((string) ($contract['extra_json'] ?? ''), true);
        $extra['annual_kwh'] = (string) (int) round((float) $request['kwh']);

        $this->contracts->update($id, $scope, ['extra_json' => ExtraFields::toJson($extra)]);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * (335) Η πρόταση για κάθε σύμβαση που λήγει. Το `extra_json` χρειάζεται
     * μόνο για την κατανάλωση και ΔΕΝ φεύγει προς τον browser.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withOffers(array $rows, UserScope $scope): array
    {
        // (344) Ο υπολογισμός ζει στο Infrastructure\RenewalOffers, κοινός με
        // την καθημερινή «Κλήση ανανέωσης».
        return (new RenewalOffers($this->providerAccess, $this->providers))->attach($rows, $scope);
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>|null
     */
    private function offeredProgram(int $programId, array $source, UserScope $scope): ?array
    {
        if ($programId <= 0 || $this->providers === null) {
            return null;
        }

        $access = $this->providerAccess->forScope($scope);

        foreach ($this->providers->activePrograms() as $program) {
            if (
                (int) $program['id'] === $programId
                && (string) $program['energy_type'] === (string) ($source['energy_type'] ?? '')
                && $access->allows((int) $program['provider_id'])
            ) {
                return $program;
            }
        }

        return null;
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
        // gmdate() εδώ μπορούσε να χρονολογήσει την ανανέωση μία μέρα νωρίτερα
        // κοντά στα μεσάνυχτα ώρας Ελλάδας -- ίδια κλάση σφάλματος με το
        // AnalyticsController, εύρημα ελέγχου ασφαλείας/λογικής (26/08/2026).
        $today = current_time('Y-m-d');

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
