<?php

/**
 * (379) GET/POST /me/profile — «Το προφίλ μου». Κάθε χρήστης διαβάζει και
 * αλλάζει μόνο τα δικά του· δεν υπάρχει id στη διαδρομή.
 * docs/UI-PROFILE.html.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Domain\Profile\ProfilePrefs;
use EnergyCRM\Infrastructure\UiPreference;
use EnergyCRM\Infrastructure\UserProfile;
use WP_REST_Request;
use WP_REST_Response;

final class ProfileController implements Controller
{
    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/me/profile', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'show'],
                'permission_callback' => Guards::crmUser(),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'store'],
                'permission_callback' => Guards::crmUser(),
                'args'                => [
                    'first_name' => ['type' => 'string'],
                    'last_name'  => ['type' => 'string'],
                    'start_view' => ['type' => 'string', 'enum' => ProfilePrefs::START_VIEWS],
                    'scope'      => ['type' => 'string', 'enum' => ProfilePrefs::SCOPES],
                    'font'       => ['type' => 'string', 'enum' => ProfilePrefs::FONTS],
                ],
            ],
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->payload(get_current_user_id()), 200);
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $body   = $request->get_json_params();
        $body   = is_array($body) ? $body : [];

        foreach (['first_name', 'last_name'] as $key) {
            if (array_key_exists($key, $body) && trim((string) $body[$key]) === '') {
                return new WP_REST_Response(
                    ['ok' => false, 'error' => 'Το όνομα και το επώνυμο δεν μένουν κενά.'],
                    400
                );
            }
        }

        $allowed = array_merge(array_keys(ProfilePrefs::defaults()), ['first_name', 'last_name']);
        UserProfile::save($userId, array_intersect_key($body, array_flip($allowed)));

        return new WP_REST_Response($this->payload($userId), 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $userId): array
    {
        return [
            'ok'      => true,
            'profile' => UserProfile::forUser($userId),
            // Η εναλλαγή κλασικής/νέας εμφάνισης όσο είναι σε δοκιμή: μόνο διαχειριστής.
            'can_ui'  => UiPreference::mayTry($userId),
            'ui'      => UiPreference::forUser($userId),
        ];
    }
}
