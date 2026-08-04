<?php

/**
 * GET /renewals — contracts approaching the end of their term.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Persistence\ContractRepository;
use WP_REST_Request;
use WP_REST_Response;

final class RenewalsController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
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
            'rows' => $this->contracts->expiring($scope, (int) $request['days']),
        ], 200);
    }
}
