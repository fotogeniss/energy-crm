<?php

/**
 * (343) GET /store — η καρτέλα «Κατάστημα» στην Αρχική.
 *
 * Μόνο για όποιον έχει ομάδα (MANAGE_TEAM) και για τον διαχειριστή: τι έγινε
 * σήμερα, τι θέλει κίνηση και πού βρίσκεται κάθε πωλητής. Κάθε αριθμός ανοίγει
 * μια οθόνη που ήδη υπάρχει, οπότε εδώ μόνο μετράμε. Mockup:
 * docs/UI-STORE-DASHBOARD.html.
 *
 * Χωρίς ποσά: τα «Ενεργές μήνα» είναι πλήθος αιτήσεων, ώστε να μη χρειάζεται
 * δικαίωμα προμηθειών.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Notifications;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Analytics\StoreFigures;
use EnergyCRM\Domain\Contract\CloseReason;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\StoreRepository;
use EnergyCRM\Persistence\TeamActivityRepository;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Response;

final class StoreDashboardController implements Controller
{
    /** «Online» = άγγιξε αίτηση την τελευταία μισή ώρα, όπως στο «Ομάδα τώρα». */
    private const RECENT_SECONDS = 1800;

    /** «Λήγουν τις επόμενες 30 μέρες». */
    private const EXPIRING_DAYS = 30;

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly StoreRepository $store,
        private readonly TeamActivityRepository $activity,
        private readonly TeamRepository $team,
        private readonly ContractQueries $queries,
        private readonly StuckController $stuck,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/store', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::needs(Capability::MANAGE_TEAM),
        ]);
    }

    public function index(): WP_REST_Response
    {
        $scope     = $this->scopes->forCurrentUser();
        $lost      = [
            ContractStatus::ThalisRejected->value,
            ContractStatus::MnpReject->value,
            ContractStatus::Cancelled->value,
        ];
        $stats     = $this->store->contractStats($scope);
        $activated = $this->store->activatedThisMonth($scope);
        $rejected  = $this->store->lostByOwner($scope, $lost);
        $reasons   = $this->store->lostReasons($scope, $lost);

        // Όλα τα μέλη της ομάδας, και όσοι άλλοι έχουν αιτήσεις στο scope
        // (ο διαχειριστής βλέπει και αιτήσεις χρηστών που έχουν φύγει).
        $ids   = array_values(array_unique([
            ...$this->activity->memberIds($scope),
            ...array_keys($stats),
        ]));
        $names = $this->team->displayNames($ids);

        $members = [];
        $tiles   = [
            'today'         => 0,
            'yesterday'     => 0,
            'month'         => 0,
            'before'        => 0,
            'awaiting'      => 0,
            'awaiting_late' => 0,
            'lost'          => 0,
        ];

        foreach ($ids as $id) {
            if (! isset($names[$id])) {
                continue;
            }

            $stat = $stats[$id] ?? null;
            $idle = $stat['idle_seconds'] ?? null;

            $members[] = [
                'id'            => $id,
                'name'          => $names[$id],
                'is_self'       => $id === $scope->actorId(),
                'today'         => $stat['today'] ?? 0,
                'month'         => $stat['month'] ?? 0,
                'activated'     => $activated[$id] ?? 0,
                'awaiting'      => $stat['awaiting'] ?? 0,
                'lost'          => $rejected[$id] ?? 0,
                'last'          => $stat['last_activity'] ?? '',
                'online'        => $idle !== null && $idle < self::RECENT_SECONDS,
            ];
        }

        foreach ($stats as $stat) {
            foreach (['today', 'yesterday', 'month', 'before', 'awaiting', 'awaiting_late'] as $key) {
                $tiles[$key] += $stat[$key];
            }
        }

        $tiles['lost']         = array_sum($rejected);
        $tiles['month_change'] = StoreFigures::change($tiles['month'], $tiles['before']);
        $tiles['top_reason']   = $reasons !== [] ? CloseReason::label($reasons[0]['reason']) : '';

        // Πιο απασχολημένοι σήμερα πρώτα, μετά ο μήνας, μετά το όνομα.
        usort(
            $members,
            static fn (array $a, array $b): int =>
                ($b['today'] <=> $a['today']) ?: ($b['month'] <=> $a['month']) ?: strcmp($a['name'], $b['name'])
        );

        return new WP_REST_Response([
            'ok'      => true,
            'tiles'   => $tiles,
            'todo'    => $this->todo($scope, $stats, $names),
            'members' => $members,
        ], 200);
    }

    /**
     * Το «Θέλει κίνηση σήμερα»: πέντε γραμμές, η καθεμία με το ποιος/ποιο.
     *
     * @param array<int, array<string, mixed>> $stats
     * @param array<int, string>               $names
     *
     * @return list<array{key: string, count: int, split: list<array{name: string, count: int}>}>
     */
    private function todo(UserScope $scope, array $stats, array $names): array
    {
        $late = [];

        foreach ($stats as $id => $stat) {
            for ($i = 0; $i < (int) $stat['awaiting_late']; $i++) {
                $late[] = $names[$id] ?? '—';
            }
        }

        $members = $this->activity->memberIds($scope);
        $legacy  = class_exists(ECRM_Notifications::class);
        $docs    = $legacy ? ECRM_Notifications::missing_docs_for($members) : [];
        $leads   = $legacy ? ECRM_Notifications::overdue_leads_for($members) : [];
        $byName  = static fn (array $rows, string $column = 'owner'): array => array_map(
            static fn (array $r): string => $names[(int) ($r[$column] ?? 0)] ?? '—',
            $rows
        );

        $expiring = array_values(array_filter(
            $this->queries->expiring($scope, self::EXPIRING_DAYS),
            static fn (array $r): bool => (int) $r['days_left'] >= 0
        ));

        $stuck = array_map(
            static fn (array $r): string => (string) ($r['provider_name'] ?? ''),
            $this->stuck->find($scope)
        );

        return [
            $this->line('signatures', $late),
            $this->line('docs', $byName($docs)),
            $this->line('stuck', $stuck),
            $this->line('leads', $byName($leads)),
            $this->line('expiring', $byName($expiring, 'partner_user_id')),
        ];
    }

    /**
     * @param list<string> $keys
     *
     * @return array{key: string, count: int, split: list<array{name: string, count: int}>}
     */
    private function line(string $key, array $keys): array
    {
        return ['key' => $key, 'count' => count($keys), 'split' => StoreFigures::split($keys)];
    }
}
