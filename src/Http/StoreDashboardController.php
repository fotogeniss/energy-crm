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
use EnergyCRM\Domain\Analytics\Month;
use EnergyCRM\Domain\Analytics\StoreFigures;
use EnergyCRM\Domain\Contract\CloseReason;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Infrastructure\StoreMonthReport;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\StoreRepository;
use EnergyCRM\Persistence\TargetRepository;
use EnergyCRM\Persistence\TeamActivityRepository;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;
use WP_REST_Response;

final class StoreDashboardController implements Controller
{
    /** «Online» = άγγιξε αίτηση την τελευταία μισή ώρα, όπως στο «Ομάδα τώρα». */
    private const RECENT_SECONDS = 1800;

    /** «Λήγουν τις επόμενες 30 μέρες». */
    private const EXPIRING_DAYS = 30;

    /** (345) Πόσους μήνες πίσω δείχνει η αναφορά (μαζί με τον τρέχοντα). */
    private const REPORT_MONTHS = 12;

    /** (345) Ανώτατος στόχος αιτήσεων ενός πωλητή σε έναν μήνα. */
    private const MAX_TARGET = 1000;

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly StoreRepository $store,
        private readonly TeamActivityRepository $activity,
        private readonly TeamRepository $team,
        private readonly ContractQueries $queries,
        private readonly StuckController $stuck,
        private readonly TargetRepository $targets,
        private readonly StoreMonthReport $reports,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/store', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::needs(Capability::MANAGE_TEAM),
        ]);
        // (345) Η μηνιαία αναφορά ενός μήνα.
        register_rest_route(Router::NAMESPACE, '/store/month', [
            'methods'             => 'GET',
            'callback'            => [$this, 'month'],
            'permission_callback' => Guards::needs(Capability::MANAGE_TEAM),
            'args'                => ['month' => ['type' => 'string', 'required' => true]],
        ]);
        // (345) Στόχοι μήνα: ανάγνωση για τον διάλογο, και αποθήκευση.
        register_rest_route(Router::NAMESPACE, '/store/targets', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'targets'],
                'permission_callback' => Guards::needs(Capability::MANAGE_TEAM),
                'args'                => ['month' => ['type' => 'string', 'default' => '']],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'saveTargets'],
                'permission_callback' => Guards::needs(Capability::MANAGE_TEAM),
            ],
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
        $names    = $this->team->displayNames($ids);
        $calendar = $this->store->calendar();
        $targets  = $this->targets->forMonth($calendar['month'], $ids);

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
                // (345) Στόχος αιτήσεων του μήνα και αν είναι πίσω από τον ρυθμό.
                'target'        => $targets[$id] ?? null,
                'behind'        => StoreFigures::behindPace(
                    (int) ($stat['month'] ?? 0),
                    $targets[$id] ?? null,
                    // Μέρες που ΠΕΡΑΣΑΝ: η σημερινή δεν έχει τελειώσει.
                    $calendar['day'] - 1,
                    $calendar['days']
                ),
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
        $tiles['target']       = $targets === [] ? null : array_sum($targets);
        $tiles['of_target']    = StoreFigures::ofTarget($tiles['month'], $tiles['target']);

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
            'month'   => $calendar['month'],
            'months'  => array_map(
                static fn (string $m): array => ['month' => $m, 'label' => Month::label($m), 'name' => Month::name($m)],
                Month::recent($calendar['month'], self::REPORT_MONTHS)
            ),
        ], 200);
    }

    /** (345) GET /store/month?month=YYYY-MM -- η αναφορά ενός μήνα. */
    public function month(WP_REST_Request $request): WP_REST_Response
    {
        $month   = Month::parse((string) $request['month']);
        $current = $this->store->calendar()['month'];

        $back    = $month === null ? -1 : Month::distance($month, $current);

        if ($back < 0 || $back >= self::REPORT_MONTHS) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Μη έγκυρος μήνας.'], 422);
        }

        return new WP_REST_Response(
            ['ok' => true, 'report' => $this->reports->build($this->scopes->forCurrentUser(), $month)],
            200
        );
    }

    /**
     * (345) GET /store/targets -- οι πωλητές της ομάδας, τι έκαναν τον
     * προηγούμενο μήνα και ο στόχος τους. Μήνας: ο τρέχων ή ο επόμενος.
     */
    public function targets(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();
        $month = $this->targetMonth((string) $request['month']);

        if ($month === null) {
            return $this->refuse('Στόχοι μπαίνουν στον τρέχοντα ή στον επόμενο μήνα.');
        }

        $ids      = $this->activity->memberIds($scope);
        $names    = $this->team->displayNames($ids);
        $previous = Month::previous($month);
        $done     = $this->store->monthByOwner($scope, Month::firstDay($previous), []);
        $current  = $this->targets->forMonth($month, $ids);
        $before   = $this->targets->forMonth($previous, $ids);
        $rows     = [];

        foreach ($ids as $id) {
            if (! isset($names[$id])) {
                continue;
            }

            $rows[] = [
                'id'              => $id,
                'name'            => $names[$id],
                'previous'        => ($done[$id] ?? ['applications' => 0])['applications'],
                'previous_target' => $before[$id] ?? null,
                'target'          => $current[$id] ?? null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return new WP_REST_Response([
            'ok'       => true,
            'month'    => $month,
            'label'    => Month::label($month),
            'previous' => Month::name($previous),
            'previous_acc' => Month::accusative($previous),
            'rows'     => $rows,
        ], 200);
    }

    /**
     * (345) POST /store/targets {month, targets: {userId: n|null}}. Μόνο για
     * την ομάδα του χρήστη· ό,τι άλλο id αγνοείται.
     */
    public function saveTargets(WP_REST_Request $request): WP_REST_Response
    {
        $scope  = $this->scopes->forCurrentUser();
        $params = (array) ($request->get_json_params() ?: $request->get_params());
        $month  = $this->targetMonth((string) ($params['month'] ?? ''));

        if ($month === null) {
            return $this->refuse('Στόχοι μπαίνουν στον τρέχοντα ή στον επόμενο μήνα.');
        }

        $allowed = array_flip($this->activity->memberIds($scope));
        $clean   = [];

        foreach ((array) ($params['targets'] ?? []) as $userId => $value) {
            $userId = (int) $userId;

            if (! isset($allowed[$userId])) {
                continue;
            }

            if ($value === null || $value === '') {
                $clean[$userId] = null;
                continue;
            }

            if (! is_numeric($value) || (int) $value < 0 || (int) $value > self::MAX_TARGET) {
                return $this->refuse('Ο στόχος είναι αριθμός από 0 έως ' . self::MAX_TARGET . '.');
            }

            $clean[$userId] = (int) $value;
        }

        $this->targets->save($month, $clean, $scope->actorId());

        return new WP_REST_Response(['ok' => true, 'saved' => count($clean)], 200);
    }

    private function refuse(string $error): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => false, 'error' => $error], 422);
    }

    /** Ο τρέχων μήνας (κενό) ή ο επόμενος· τίποτα άλλο. */
    private function targetMonth(string $asked): ?string
    {
        $current = $this->store->calendar()['month'];

        if ($asked === '') {
            return $current;
        }

        $month = Month::parse($asked);

        return $month !== null && in_array(Month::distance($current, $month), [0, 1], true) ? $month : null;
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
