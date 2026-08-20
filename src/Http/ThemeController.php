<?php

/**
 * GET  /theme  η εμφάνιση του τρέχοντος χρήστη
 * POST /theme  την αλλάζει
 *
 * Ο controller δεν ξέρει πού αποθηκεύεται η προτίμηση ούτε ποιες τιμές
 * επιτρέπονται· και τα δύο τα ξέρει το ThemePreference, που είναι και ο
 * αναγνώστης του κελύφους. Εδώ μένει μόνο το HTTP.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Infrastructure\ThemePreference;
use WP_REST_Request;
use WP_REST_Response;

final class ThemeController implements Controller
{
    public function __construct(private readonly ScopeResolver $scopes)
    {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/theme', [
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
                    'theme' => [
                        'type'     => 'string',
                        'required' => true,
                        'enum'     => ThemePreference::allowed(),
                    ],
                ],
            ],
        ]);
    }

    public function show(): WP_REST_Response
    {
        return new WP_REST_Response(
            ['ok' => true, 'theme' => ThemePreference::forUser($this->actor())],
            200
        );
    }

    /**
     * Επιστρέφει ό,τι ισχύει μετά τη γραφή, όχι ό,τι στάλθηκε.
     *
     * Το `enum` παραπάνω κόβει ήδη τα άγνωστα με 400 από τη WordPress· η
     * δεύτερη φύλαξη ζει μέσα στο ThemePreference::save() και ισχύει και για
     * καλούντες που δεν περνούν από εδώ.
     */
    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $theme = ThemePreference::save($this->actor(), (string) $request['theme']);

        return new WP_REST_Response(['ok' => true, 'theme' => $theme], 200);
    }

    private function actor(): int
    {
        return $this->scopes->forCurrentUser()->actorId();
    }
}
