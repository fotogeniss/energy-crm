<?php

/**
 * GET /contracts       the list, with per-status counts for the tabs
 * GET /contracts/{id}  one contract with its events, files and checklist
 *
 * Reads only. The writes — save, status, bulk, delete — stay in ECRM_REST for
 * now and move next; splitting the two keeps this change reviewable and means
 * a mistake here cannot corrupt anything.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_DB;
use ECRM_Docs;
use ECRM_Files;
use ECRM_Tracking;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ContractsReadController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly EventRepository $events,
        private readonly FileRepository $files,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'scope'  => ['type' => 'string', 'default' => 'own', 'enum' => ['own', 'team']],
                'status' => [
                    'type'    => 'string',
                    'default' => '',
                    'enum'    => ['', ...array_keys(ECRM_DB::statuses())],
                ],
                'q' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();

        if ($request['scope'] !== 'team') {
            $scope = $scope->toSelfOnly();
        }

        $rows = $this->contracts->search(
            $scope,
            (string) $request['status'],
            trim((string) $request['q'])
        );

        return new WP_REST_Response([
            'ok'       => true,
            'rows'     => $this->withOwnerNames($rows),
            'counts'   => $this->counts($scope),
            'statuses' => ECRM_DB::statuses(),
        ], 200);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $id  = (int) $request['id'];
        $row = $this->contracts->findDetailed($id, $this->scopes->forCurrentUser());

        if ($row === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $row['events'] = $this->events->forContract($id);
        $row['files']  = array_map(
            static function (array $file): array {
                $file['url']      = ECRM_Files::url((int) $file['id']);
                $file['is_image'] = str_starts_with((string) $file['mime'], 'image/');

                // Storage layout is ours, not the client's business.
                unset($file['path'], $file['attachment_id']);

                return $file;
            },
            $this->files->forContract($id)
        );

        $row['extra'] = empty($row['extra_json'])
            ? []
            : (array) json_decode((string) $row['extra_json'], true);

        $row['track_url']     = ECRM_Tracking::url($id);
        $row['doc_checklist'] = ECRM_Docs::checklist($id, (string) ($row['activation_type'] ?? ''));
        $row['doc_kinds']     = ECRM_Docs::kinds();

        return new WP_REST_Response([
            'ok'               => true,
            'contract'         => $row,
            'statuses'         => ECRM_DB::statuses(),
            'activation_types' => ECRM_DB::activation_types(),
        ], 200);
    }

    /**
     * Owner names for the whole page in one query — a lookup per row would
     * reintroduce the N+1 removed in step 3.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function withOwnerNames(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (array $r): int => (int) ($r['partner_user_id'] ?? 0), $rows)
        )));

        $names = [];

        foreach ($ids === [] ? [] : get_users(['include' => $ids, 'fields' => ['ID', 'display_name']]) as $user) {
            $names[(int) $user->ID] = $user->display_name;
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['partner_name'] = $names[(int) ($row['partner_user_id'] ?? 0)] ?? '—';
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function counts(UserScope $scope): array
    {
        $counts = ['all' => 0];

        foreach (array_keys(ECRM_DB::statuses()) as $status) {
            $counts[$status] = 0;
        }

        foreach ($this->contracts->countsByStatus($scope) as $status => $total) {
            $counts[$status] = $total;
            $counts['all']  += $total;
        }

        return $counts;
    }
}
