<?php

/**
 * (388) «Το προφίλ μου → Ασφάλεια». docs/UI-PROFILE-SECURITY.html.
 *
 *   GET    /me/security        πότε άλλαξε ο κωδικός, και οι συνδέσεις
 *   POST   /me/password        αλλαγή κωδικού (current, new)
 *   DELETE /me/sessions        κλείνει όλες εκτός από την τρέχουσα
 *   DELETE /me/sessions/{id}   κλείνει μία
 *
 * Μόνο για τον ίδιο τον χρήστη: κανένα id χρήστη δεν έρχεται από το αίτημα.
 * Οι κανόνες στο `Domain\Security`, το WordPress στο `Infrastructure\UserSessions`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Infrastructure\UserSessions;
use WP_REST_Request;
use WP_REST_Response;

final class SecurityController implements Controller
{
    public function __construct(private readonly UserSessions $sessions)
    {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/me/security', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => Guards::crmUser(),
        ]);

        register_rest_route(Router::NAMESPACE, '/me/password', [
            'methods'             => 'POST',
            'callback'            => [$this, 'password'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                // Χωρίς sanitize: ένας κωδικός δεν «καθαρίζεται», ελέγχεται όπως είναι.
                'current' => ['type' => 'string', 'required' => true],
                'new'     => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/me/sessions', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'destroyOthers'],
            'permission_callback' => Guards::crmUser(),
        ]);

        register_rest_route(Router::NAMESPACE, '/me/sessions/(?P<id>[a-f0-9]+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'destroy'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'string', 'required' => true]],
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();

        return new WP_REST_Response([
            'ok'                  => true,
            'password_changed_at' => $this->sessions->changedAt($userId),
            'sessions'            => $this->sessions->list($userId),
        ], 200);
    }

    public function password(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->sessions->changePassword(
            wp_get_current_user(),
            (string) $request['current'],
            (string) $request['new']
        );

        if (! $result['ok']) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => $result['error'] ?? 'Ο κωδικός δεν άλλαξε.'],
                $result['status'] ?? 400
            );
        }

        $userId = get_current_user_id();

        return new WP_REST_Response([
            'ok'                  => true,
            'closed'              => $result['closed'] ?? 0,
            'password_changed_at' => $this->sessions->changedAt($userId),
            'sessions'            => $this->sessions->list($userId),
        ], 200);
    }

    public function destroyOthers(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $closed = $this->sessions->destroyOthers($userId);

        return new WP_REST_Response([
            'ok'       => true,
            'closed'   => $closed,
            'sessions' => $this->sessions->list($userId),
        ], 200);
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();

        if (! $this->sessions->destroy($userId, (string) $request['id'])) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Η σύνδεση δεν βρέθηκε.'], 404);
        }

        return new WP_REST_Response(['ok' => true, 'sessions' => $this->sessions->list($userId)], 200);
    }
}
