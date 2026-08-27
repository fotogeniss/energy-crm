<?php

/**
 * GET    /leads              the funnel, with counts per stage
 * POST   /leads              create
 * POST   /leads/{id}         update
 * DELETE /leads/{id}         remove
 * POST   /leads/{id}/convert turn a lead into a draft contract
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Files;
use ECRM_Leads;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\LeadRepository;
use WP_REST_Request;
use WP_REST_Response;

final class LeadsController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly LeadRepository $leads,
        private readonly ContractRepository $contracts,
        private readonly CustomerRepository $customers,
    ) {
    }

    public function routes(): void
    {
        $guard = Guards::needs(Capability::MANAGE_LEADS);

        register_rest_route(Router::NAMESPACE, '/leads', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'index'],
                'permission_callback' => $guard,
                'args'                => [
                    'stage' => [
                        'type'    => 'string',
                        'default' => '',
                        'enum'    => ['', ...array_keys(ECRM_Leads::stages())],
                    ],
                    'q' => [
                        'type'              => 'string',
                        'default'           => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'save'],
                'permission_callback' => $guard,
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/leads/(?P<id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'save'],
                'permission_callback' => $guard,
                'args'                => ['id' => ['type' => 'integer', 'required' => true]],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'destroy'],
                'permission_callback' => $guard,
                'args'                => ['id' => ['type' => 'integer', 'required' => true]],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/leads/(?P<id>\d+)/convert', [
            'methods'             => 'POST',
            'callback'            => [$this, 'convert'],
            'permission_callback' => Guards::needs(Capability::CREATE_CONTRACT),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $scope   = $this->scopes->forCurrentUser();
        $stages  = ECRM_Leads::stages();
        $sources = ECRM_Leads::sources();

        $leads = array_map(
            static fn (array $row): array => [
                'id'           => (int) $row['id'],
                'name'         => $row['name'],
                'phone'        => $row['phone'],
                'email'        => $row['email'],
                'source'       => $row['source'],
                'source_label' => $sources[$row['source']] ?? '',
                'energy_type'  => $row['energy_type'],
                'stage'        => $row['stage'],
                'stage_label'  => $stages[$row['stage']] ?? $row['stage'],
                'callback_at'  => $row['callback_at'],
                'interest'     => $row['interest'],
                'notes'        => $row['notes'],
                'contract_id'  => (int) ($row['contract_id'] ?? 0),
                'lost_reason'  => $row['lost_reason'],
                'updated_at'   => $row['updated_at'],
            ],
            $this->leads->search($scope, (string) $request['stage'], trim((string) $request['q']))
        );

        return new WP_REST_Response([
            'ok'      => true,
            'leads'   => $leads,
            'counts'  => $this->leads->countsByStage($scope),
            'stages'  => $stages,
            'sources' => $sources,
        ], 200);
    }

    public function save(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: $request->get_params();
        $scope  = $this->scopes->forCurrentUser();
        $id     = (int) ($request['id'] ?? 0);

        $data = $this->fieldsFrom($params);

        if ($id > 0) {
            return $this->leads->update($id, $scope, $data)
                ? new WP_REST_Response(['ok' => true, 'id' => $id], 200)
                : new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
        }

        if ($data['name'] === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'Το όνομα είναι υποχρεωτικό.'], 400);
        }

        $data['stage'] ??= 'new';
        $id = $this->leads->create($data, $scope);

        return new WP_REST_Response(['ok' => $id > 0, 'id' => $id], $id > 0 ? 200 : 500);
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        return $this->leads->delete((int) $request['id'], $this->scopes->forCurrentUser())
            ? new WP_REST_Response(['ok' => true], 200)
            : new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
    }

    /**
     * Turn a lead into a draft contract, once.
     *
     * A lead already converted returns its existing contract instead of making
     * a second one: the button is easy to press twice.
     */
    public function convert(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();
        $id    = (int) $request['id'];
        $lead  = $this->leads->find($id, $scope);

        if ($lead === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
        }

        if (! empty($lead['contract_id'])) {
            return new WP_REST_Response([
                'ok'          => true,
                'contract_id' => (int) $lead['contract_id'],
                'existing'    => true,
            ], 200);
        }

        [$first, $last] = self::splitName((string) $lead['name']);

        $customerId = $this->customers->create([
            'customer_type' => 'individual',
            'first_name'    => $first,
            'last_name'     => $last,
            'mobile'        => (string) $lead['phone'],
            'email'         => (string) $lead['email'],
        ]);

        $energy = in_array($lead['energy_type'], ['power', 'gas', 'mobile'], true)
            ? (string) $lead['energy_type']
            : 'power';

        $contractId = $this->contracts->create([
            'customer_id'   => $customerId ?: null,
            'energy_type'   => $energy,
            'category'      => 'home',
            'customer_type' => 'individual',
            'status'        => 'draft',
            'notes'         => $lead['interest'] ? 'Από lead: ' . $lead['interest'] : null,
        ], $scope);

        if ($contractId <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Η μετατροπή απέτυχε.'], 500);
        }

        $this->contracts->assignCode($contractId, $scope);

        // Εύρημα ελέγχου ασφαλείας/λογικής #4 (26/08/2026): το `!empty()`
        // παραπάνω ΔΕΝ είναι το σημείο ατομικότητας -- είναι απλώς γρήγορο
        // μονοπάτι για το κοινό, μη-ταυτόχρονο, περίπτωση, και ό,τι
        // φτιάχτηκε ως εδώ (πελάτης/πρόχειρη σύμβαση) δεν έχει ακόμα
        // δεσμεύσει το lead. Το πραγματικό κλείδωμα είναι αυτό το guarded
        // UPDATE μέσα στη finishConversion() -- βλ. εκεί για γιατί η σειρά
        // είναι "φτιάξε πρώτα, διεκδίκησε μετά" και όχι το αντίστροφο.
        if (! $this->leads->finishConversion($id, $scope, $contractId)) {
            // Χάσαμε τη διεκδίκηση -- κάποιο άλλο αίτημα ολοκλήρωσε πρώτο (ή
            // το lead δεν είναι πια στο scope). Ο μόλις φτιαγμένος πρόχειρος
            // δεν έχει ξαναδεί από κανέναν -- διαγράφεται, όχι ορφανός.
            $this->contracts->delete($contractId, $scope);

            $fresh = $this->leads->find($id, $scope);

            if ($fresh !== null && ! empty($fresh['contract_id'])) {
                return new WP_REST_Response([
                    'ok'          => true,
                    'contract_id' => (int) $fresh['contract_id'],
                    'existing'    => true,
                ], 200);
            }

            return new WP_REST_Response(['ok' => false, 'error' => 'Η μετατροπή εκτελείται ήδη.'], 409);
        }

        // Τα έγγραφα που ανέβασε ο ΙΔΙΟΣ ο πελάτης από τον δημόσιο σύνδεσμο
        // ακολουθούν τη σύμβαση αντί να ξαναζητηθούν. Μετά το finishConversion()
        // και όχι πριν: αν χανόταν η διεκδίκηση, ο πρόχειρος διαγράφεται και τα
        // αρχεία θα είχαν μετακομίσει σε σύμβαση που δεν υπάρχει πια.
        ECRM_Files::attach_lead_to_contract($id, $contractId);

        return new WP_REST_Response(['ok' => true, 'contract_id' => $contractId], 200);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function fieldsFrom(array $params): array
    {
        $stage  = array_key_exists((string) ($params['stage'] ?? ''), ECRM_Leads::stages())
            ? (string) $params['stage'] : null;
        $source = array_key_exists((string) ($params['source'] ?? ''), ECRM_Leads::sources())
            ? (string) $params['source'] : null;
        $energy = in_array($params['energy_type'] ?? '', ['power', 'gas', 'mobile'], true)
            ? (string) $params['energy_type'] : null;

        $callback  = trim((string) ($params['callback_at'] ?? ''));
        $timestamp = $callback === '' ? false : strtotime($callback);

        $data = [
            'name'        => sanitize_text_field((string) ($params['name'] ?? '')),
            'phone'       => sanitize_text_field((string) ($params['phone'] ?? '')),
            'email'       => sanitize_email((string) ($params['email'] ?? '')),
            'source'      => $source,
            'energy_type' => $energy,
            'interest'    => sanitize_text_field((string) ($params['interest'] ?? '')),
            'notes'       => sanitize_textarea_field((string) ($params['notes'] ?? '')),
            'callback_at' => $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp),
            'lost_reason' => isset($params['lost_reason'])
                ? sanitize_text_field((string) $params['lost_reason']) : null,
            'updated_at'  => current_time('mysql'),
        ];

        if ($stage !== null) {
            $data['stage'] = $stage;
        }

        return $data;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
