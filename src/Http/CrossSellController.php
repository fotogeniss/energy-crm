<?php

/**
 * (333) GET  /cross-sell                       -- «Χωρίς αέριο» / «Χωρίς ρεύμα»
 *       POST /customers/{id}/cross-sell-snooze -- «Όχι τώρα» για 90 μέρες
 *
 * Λεπτός: ποιος κανόνας στο Domain\Customer\CrossSell, ποιο SQL στο
 * Persistence\CrossSellQueries. Το «+ Αέριο / + Ρεύμα» της λίστας είναι το
 * υπάρχον POST /contracts/{id}/companion (326) -- καμία δεύτερη διαδρομή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Customer\CrossSell;
use EnergyCRM\Persistence\CrossSellQueries;
use EnergyCRM\Persistence\CustomerEventRepository;
use EnergyCRM\Persistence\CustomerRepository;
use WP_REST_Request;
use WP_REST_Response;

final class CrossSellController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly CrossSellQueries $queries,
        private readonly CustomerRepository $customers,
        private readonly CustomerEventRepository $events,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/cross-sell', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'want'     => ['type' => 'string', 'enum' => ['gas', 'power'], 'default' => 'gas'],
                'scope'    => ['type' => 'string', 'enum' => ['own', 'team'], 'default' => 'own'],
                'city'     => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'category' => ['type' => 'string', 'enum' => ['', 'home', 'business', 'communal'], 'default' => ''],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/customers/(?P<id>\d+)/cross-sell-snooze', [
            'methods'             => 'POST',
            'callback'            => [$this, 'snooze'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'   => ['type' => 'integer', 'required' => true],
                'want' => ['type' => 'string', 'enum' => ['gas', 'power'], 'required' => true],
                'note' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
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

        $want     = (string) $request['want'];
        $today    = current_time('Y-m-d');
        $city     = (string) $request['city'];
        $category = (string) $request['category'];

        $rows = array_map(
            static function (array $row): array {
                $name = $row['company_name']
                    ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

                return [
                    'id'           => (int) $row['id'],
                    'name'         => $name !== '' ? $name : '—',
                    'city'         => (string) ($row['city'] ?? ''),
                    'phone'        => (string) (($row['mobile'] ?? '') ?: ($row['phone'] ?? '')),
                    'category'     => (string) ($row['category'] ?? ''),
                    'contract_id'  => (int) $row['contract_id'],
                    'draft_id'     => $row['draft_id'] !== null ? (int) $row['draft_id'] : null,
                    'active_since' => $row['active_since'],
                ];
            },
            $this->queries->candidates($scope, $want, $city, $category, $today)
        );

        $other = $want === 'gas' ? 'power' : 'gas';

        return new WP_REST_Response([
            'ok'     => true,
            'want'   => $want,
            'rows'   => $rows,
            'counts' => [
                $want  => count($rows),
                $other => count($this->queries->candidates($scope, $other, $city, $category, $today)),
            ],
            'cities' => $this->queries->cities($scope),
        ], 200);
    }

    public function snooze(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        $customerId = (int) $request['id'];

        if (! $this->customers->isReachable($customerId, $scope)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε ο πελάτης.'], 404);
        }

        $field = CrossSell::snoozeField((string) $request['want']);

        $this->events->record($customerId, $scope->actorId(), [
            $field => ['old' => '', 'new' => CrossSell::snoozeNote((string) $request['note'])],
        ]);

        return new WP_REST_Response(['ok' => true], 200);
    }
}
