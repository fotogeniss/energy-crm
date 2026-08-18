<?php

/**
 * GET /customers        the customer book, scoped to the actor
 * GET /customers/check  is this ΑΦΜ or supply already on file?
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Validate;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Persistence\CustomerRepository;
use WP_REST_Request;
use WP_REST_Response;

/*
 * No NotAuthenticated handling here: Guards::crmUser() has already established
 * a logged-in user before any callback runs, so forCurrentUser() cannot throw.
 * Catching it anyway would be dead code pretending to be caution.
 */
final class CustomersController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly CustomerRepository $customers,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/customers', [
            'methods'             => 'GET',
            'callback'            => [$this, 'index'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'scope' => [
                    'type'    => 'string',
                    'default' => 'own',
                    'enum'    => ['own', 'team'],
                ],
                'q' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/customers/check', [
            'methods'             => 'GET',
            'callback'            => [$this, 'check'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'afm' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'supply' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();

        if ($request['scope'] !== 'team') {
            $scope = $scope->toSelfOnly();
        }

        $rows = array_map(
            static function (array $row): array {
                $name = $row['company_name']
                    ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

                return [
                    'id'        => (int) $row['id'],
                    'name'      => $name !== '' ? $name : '—',
                    'afm'       => $row['afm'],
                    'phone'     => $row['phone'],
                    'email'     => $row['email'],
                    'contracts' => (int) $row['contracts'],
                    'last_at'   => $row['last_at'],
                ];
            },
            $this->customers->search($scope, trim((string) $request['q']))
        );

        return new WP_REST_Response(
            ['ok' => true, 'rows' => $rows, 'count' => count($rows)],
            200
        );
    }

    public function check(WP_REST_Request $request): WP_REST_Response
    {
        // Ίδια κανονικοποίηση με το /contracts/duplicate. Με σκέτο trim() το
        // ΑΦΜ με κενά έψαχνε άλλο hash από αυτό που είχε αποθηκευτεί.
        $afm    = ECRM_Validate::digits((string) $request['afm']);
        $supply = trim((string) $request['supply']);

        if ($afm === '' && $supply === '') {
            return new WP_REST_Response(['ok' => true, 'matches' => []], 200);
        }

        return new WP_REST_Response([
            'ok'      => true,
            'matches' => $this->customers->duplicatesOf(
                $this->scopes->forCurrentUser(),
                $afm,
                $supply
            ),
        ], 200);
    }
}
