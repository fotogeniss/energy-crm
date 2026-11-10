<?php

/**
 * (353) POST /ui: κλασική ή νέα εμφάνιση. Μόνο διαχειριστής, όσο είναι σε δοκιμή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Infrastructure\UiPreference;
use WP_REST_Request;
use WP_REST_Response;

final class UiController implements Controller
{
    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/ui', [
            'methods'             => 'POST',
            'callback'            => [$this, 'store'],
            'permission_callback' => static fn (): bool => UiPreference::mayTry(get_current_user_id()),
            'args'                => [
                'ui' => ['type' => 'string', 'required' => true, 'enum' => UiPreference::allowed()],
            ],
        ]);
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $ui = UiPreference::save(get_current_user_id(), (string) $request['ui']);

        return new WP_REST_Response(['ok' => true, 'ui' => $ui], 200);
    }
}
