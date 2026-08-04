<?php

/**
 * GET    /tasks       list, filtered
 * POST   /tasks       create
 * POST   /tasks/{id}  update
 * DELETE /tasks/{id}  remove
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\TaskRepository;
use WP_REST_Request;
use WP_REST_Response;

final class TasksController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly TaskRepository $tasks,
        private readonly ContractRepository $contracts,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/tasks', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'index'],
                'permission_callback' => Guards::crmUser(),
                'args'                => [
                    'scope'  => ['type' => 'string', 'default' => 'own', 'enum' => ['own', 'team']],
                    'filter' => [
                        'type'    => 'string',
                        'default' => 'open',
                        'enum'    => ['open', 'done', 'today', 'overdue'],
                    ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create'],
                'permission_callback' => Guards::crmUser(),
                'args'                => [
                    'title' => [
                        'type'              => 'string',
                        'required'          => true,
                        'minLength'         => 1,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'note'        => ['type' => 'string', 'default' => ''],
                    'due_at'      => ['type' => 'string', 'default' => ''],
                    'priority'    => ['type' => 'string', 'default' => 'normal',
                                      'enum' => ['low', 'normal', 'high']],
                    'assigned_to' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                    'contract_id' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/tasks/(?P<id>\d+)', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'update'],
                'permission_callback' => Guards::crmUser(),
                'args'                => [
                    'id'       => ['type' => 'integer', 'required' => true],
                    'status'   => ['type' => 'string', 'enum' => ['open', 'done']],
                    'title'    => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'note'     => ['type' => 'string'],
                    'due_at'   => ['type' => 'string'],
                    'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high']],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'destroy'],
                'permission_callback' => Guards::crmUser(),
                'args'                => ['id' => ['type' => 'integer', 'required' => true]],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();

        if ($request['scope'] !== 'team') {
            $scope = $scope->toSelfOnly();
        }

        return new WP_REST_Response([
            'ok'       => true,
            'rows'     => $this->tasks->search($scope, (string) $request['filter']),
            'can_team' => $scope->isTeamWide() || $scope->isAdministrator(),
        ], 200);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();

        // Assign to self unless the target is inside the actor's own scope:
        // a task is work, and handing work to a stranger is not a thing.
        $assignee = (int) $request['assigned_to'];

        if ($assignee <= 0 || ! $scope->includes($assignee)) {
            $assignee = $scope->actorId();
        }

        $contractId = (int) $request['contract_id'];
        $contract   = $contractId > 0 ? $this->contracts->find($contractId, $scope) : null;

        $id = $this->tasks->create([
            'contract_id' => $contract === null ? null : $contractId,
            'customer_id' => $contract === null ? null : ($contract['customer_id'] ?: null),
            'assigned_to' => $assignee,
            'created_by'  => $scope->actorId(),
            'title'       => (string) $request['title'],
            'note'        => sanitize_textarea_field((string) $request['note']),
            'due_at'      => self::dueDate((string) $request['due_at']),
            'priority'    => (string) $request['priority'],
            'status'      => 'open',
        ]);

        return new WP_REST_Response(['ok' => $id > 0, 'id' => $id], $id > 0 ? 200 : 500);
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();
        $data  = [];

        if ($request['status'] !== null) {
            $data['status']  = (string) $request['status'];
            $data['done_at'] = $data['status'] === 'done' ? current_time('mysql') : null;
        }

        if ($request['title'] !== null && trim((string) $request['title']) !== '') {
            $data['title'] = (string) $request['title'];
        }

        if ($request['note'] !== null) {
            $data['note'] = sanitize_textarea_field((string) $request['note']);
        }

        if ($request['due_at'] !== null) {
            $data['due_at'] = self::dueDate((string) $request['due_at']);
        }

        if ($request['priority'] !== null) {
            $data['priority'] = (string) $request['priority'];
        }

        if ($data === []) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        return $this->tasks->update((int) $request['id'], $scope, $data)
            ? new WP_REST_Response(['ok' => true], 200)
            : new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        return $this->tasks->delete((int) $request['id'], $this->scopes->forCurrentUser())
            ? new WP_REST_Response(['ok' => true], 200)
            : new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
    }

    /**
     * A datetime-local value as MySQL datetime, or null.
     *
     * Parsed and reformatted in the site's timezone so the wall-clock time the
     * agent typed is the one stored, matching current_time('mysql') used in
     * the overdue comparison.
     */
    private static function dueDate(string $value): ?string
    {
        $value = trim(str_replace('T', ' ', $value));

        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }
}
