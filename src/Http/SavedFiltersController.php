<?php

/**
 * GET    /filters        the current user's saved contract filters
 * POST   /filters        save one
 * DELETE /filters/{idx}  remove one by position
 *
 * Stored in user meta rather than a table: they belong to one person, they are
 * a handful per user, and nothing ever queries across them.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\ScopeResolver;
use WP_REST_Request;
use WP_REST_Response;

final class SavedFiltersController implements Controller
{
    /**
     * The key the legacy handlers used. Changing it would silently empty
     * everyone's saved filters, so it stays exactly as it was.
     */
    private const META_KEY = 'ecrm_saved_filters';

    /** Enough for anyone, and a bound on what a script could stuff in there. */
    private const MAX_FILTERS = 20;

    public function __construct(private readonly ScopeResolver $scopes)
    {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/filters', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'index'],
                'permission_callback' => Guards::crmUser(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'store'],
                'permission_callback' => Guards::crmUser(),
                'args'                => [
                    'name' => [
                        'type'              => 'string',
                        'required'          => true,
                        'minLength'         => 1,
                        'maxLength'         => 40,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'status' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                    'q'      => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                    'scope'  => ['type' => 'string', 'default' => 'own', 'enum' => ['own', 'team']],
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/filters/(?P<idx>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'destroy'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['idx' => ['type' => 'integer', 'required' => true, 'minimum' => 0]],
        ]);
    }

    public function index(): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => true, 'filters' => $this->saved()], 200);
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $filters = $this->saved();

        if (count($filters) >= self::MAX_FILTERS) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Έφτασες το όριο αποθηκευμένων φίλτρων.'],
                409
            );
        }

        $filters[] = [
            'name'   => (string) $request['name'],
            'status' => (string) $request['status'],
            'q'      => (string) $request['q'],
            'scope'  => (string) $request['scope'],
        ];

        $this->persist($filters);

        return new WP_REST_Response(['ok' => true, 'filters' => $filters], 200);
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $filters = $this->saved();
        $index   = (int) $request['idx'];

        if (! array_key_exists($index, $filters)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε.'], 404);
        }

        unset($filters[$index]);
        $filters = array_values($filters);

        $this->persist($filters);

        return new WP_REST_Response(['ok' => true, 'filters' => $filters], 200);
    }

    /**
     * @return list<array<string, string>>
     */
    private function saved(): array
    {
        $stored = get_user_meta($this->actor(), self::META_KEY, true);

        return is_array($stored) ? array_values($stored) : [];
    }

    /**
     * @param list<array<string, string>> $filters
     */
    private function persist(array $filters): void
    {
        update_user_meta($this->actor(), self::META_KEY, $filters);
    }

    private function actor(): int
    {
        return $this->scopes->forCurrentUser()->actorId();
    }
}
