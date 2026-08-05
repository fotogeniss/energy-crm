<?php

/**
 * GET /team/live — one row per team member, refreshed while the manager watches.
 *
 * A word on "online": there is no session tracking here. The flag means the
 * member touched a contract in the last half hour, which is a proxy for working,
 * not for being logged in. It is named `active_recently` in the payload for that
 * reason; `online` stays alongside it so the current UI keeps working.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_DB;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Persistence\TeamActivityRepository;
use WP_REST_Request;
use WP_REST_Response;

final class TeamActivityController implements Controller
{
    /** How recently a contract must have been touched to count as "at work". */
    private const RECENT_SECONDS = 1800;

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly TeamActivityRepository $activity,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/team/live', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::needs(Capability::MANAGE_TEAM),
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $scope     = $this->scopes->forCurrentUser();
        $today     = current_time('Y-m-d');
        $stats     = $this->activity->contractStats($scope, $today, current_time('Y-m-01'));
        $openTasks = $this->activity->openTaskCounts($scope);
        $roleNames = ECRM_DB::roles();
        $now       = (int) current_time('timestamp');

        $members = [];
        $totals  = [
            'today'   => 0,
            'month'   => 0,
            'pending' => 0,
            'routed'  => 0,
            'active'  => 0,
            'online'  => 0,
        ];

        foreach ($this->activity->memberIds($scope) as $memberId) {
            $user = get_userdata($memberId);

            if (! $user) {
                continue;
            }

            $stat   = $stats[$memberId] ?? [];
            $last   = (string) ($stat['last_activity'] ?? '');
            $lastTs = $last !== '' ? strtotime($last) : false;
            $recent = $lastTs !== false && ($now - $lastTs) < self::RECENT_SECONDS;

            $row = [
                'id'              => $memberId,
                'name'            => $user->display_name,
                'role'            => $this->roleLabel((array) $user->roles, $roleNames),
                'is_self'         => $memberId === $scope->actorId(),
                'today'           => (int) ($stat['today'] ?? 0),
                'month'           => (int) ($stat['month'] ?? 0),
                'pending'         => (int) ($stat['pending'] ?? 0),
                'routed'          => (int) ($stat['routed'] ?? 0),
                'active'          => (int) ($stat['active'] ?? 0),
                'open_tasks'      => $openTasks[$memberId] ?? 0,
                'last'            => $last,
                'online'          => $recent,
                'active_recently' => $recent,
            ];

            $members[] = $row;

            foreach (['today', 'month', 'pending', 'routed', 'active'] as $key) {
                $totals[$key] += $row[$key];
            }

            if ($recent) {
                $totals['online']++;
            }
        }

        // Busiest today first, then this month. The screen is a manager's
        // glance, so the people who need attention should not be scrolled to.
        usort(
            $members,
            static fn (array $a, array $b): int =>
                ($b['today'] <=> $a['today']) ?: ($b['month'] <=> $a['month'])
        );

        return new WP_REST_Response([
            'ok'      => true,
            'totals'  => $totals,
            'members' => $members,
            'count'   => count($members),
            'ts'      => current_time('H:i'),
        ], 200);
    }

    /**
     * @param list<string>          $roles     Role slugs held by the user.
     * @param array<string, string> $roleNames CRM role slug => Greek name.
     */
    private function roleLabel(array $roles, array $roleNames): string
    {
        foreach ($roles as $role) {
            if (isset($roleNames[$role])) {
                return $roleNames[$role];
            }
        }

        return '—';
    }
}
