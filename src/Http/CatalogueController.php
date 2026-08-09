<?php

/**
 * GET /providers  the provider and tariff catalogue the form is built from
 * GET /search     the top bar's quick search across contracts
 *
 * Reference data and lookups: nothing here writes, and the catalogue is the
 * same for everyone, so only the search carries a scope.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_DB;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Forms\MobilePlans;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\ProviderRepository;
use WP_REST_Request;
use WP_REST_Response;

final class CatalogueController implements Controller
{
    /** Shorter than this and every contract matches; the UI waits too. */
    private const MIN_SEARCH_LENGTH = 2;

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ProviderRepository $providers,
        private readonly ContractRepository $contracts,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/providers', [
            'methods'             => 'GET',
            'callback'            => [$this, 'catalogue'],
            'permission_callback' => Guards::crmUser(),
        ]);

        register_rest_route(Router::NAMESPACE, '/search', [
            'methods'             => 'GET',
            'callback'            => [$this, 'search'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'q' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function catalogue(): WP_REST_Response
    {
        return new WP_REST_Response([
            'providers'        => $this->providers->active(),
            'programs'         => $this->providers->activePrograms(),
            'statuses'         => ECRM_DB::statuses(),
            'activation_types' => ECRM_DB::activation_types(),
            // The published Orizon price list, so the screen can show what a
            // mobile plan actually prints instead of leaving an editable box
            // next to a figure the paper form fixes in advance. No PII, no DB
            // read — the same static table the renderer already uses.
            'mobile_pricing'   => MobilePlans::pricingTable(),
        ], 200);
    }

    public function search(WP_REST_Request $request): WP_REST_Response
    {
        $term = trim((string) $request['q']);

        if (mb_strlen($term) < self::MIN_SEARCH_LENGTH) {
            return new WP_REST_Response(['ok' => true, 'results' => []], 200);
        }

        $statuses = ECRM_DB::statuses();

        $results = array_map(
            static function (array $row) use ($statuses): array {
                $customer = $row['company_name']
                    ?: trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

                return [
                    'id'           => (int) $row['id'],
                    'code'         => $row['code'],
                    'customer'     => $customer !== '' ? $customer : '—',
                    'afm'          => $row['afm'],
                    'provider'     => $row['provider_name'],
                    'status'       => $row['status'],
                    'status_label' => $statuses[$row['status']] ?? $row['status'],
                ];
            },
            $this->contracts->quickSearch($this->scopes->forCurrentUser(), $term)
        );

        return new WP_REST_Response(['ok' => true, 'results' => $results], 200);
    }
}
