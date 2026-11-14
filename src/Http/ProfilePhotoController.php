<?php

/**
 * (382) Φωτογραφία προφίλ. docs/UI-PROFILE-PHOTO.html.
 *
 *   POST   /me/photo      ανέβασμα (πεδίο `photo`), μόνο η δική του
 *   DELETE /me/photo      αφαίρεση, σβήνει το αρχείο
 *   GET    /photos/{id}   η εικόνα, σε συνδεδεμένο χρήστη του CRM
 *
 * Η επεξεργασία (τετράγωνο 256×256 JPEG, χωρίς μεταδεδομένα) γίνεται στο
 * `Infrastructure\ProfilePhoto`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Infrastructure\ProfilePhoto;
use WP_REST_Request;
use WP_REST_Response;

final class ProfilePhotoController implements Controller
{
    public function __construct(private readonly ProfilePhoto $photos)
    {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/me/photo', [
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'store'],
                'permission_callback' => Guards::crmUser(),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [$this, 'destroy'],
                'permission_callback' => Guards::crmUser(),
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/photos/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function store(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $files  = $request->get_file_params();
        $upload = is_array($files['photo'] ?? null) ? $files['photo'] : [];

        $error = $this->photos->save($userId, $upload);

        if ($error !== '') {
            return new WP_REST_Response(['ok' => false, 'error' => $error], 400);
        }

        return new WP_REST_Response(['ok' => true, 'photo_url' => ProfilePhoto::url($userId)], 200);
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $this->photos->remove(get_current_user_id());

        return new WP_REST_Response(['ok' => true, 'photo_url' => ''], 200);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $path = $this->photos->path((int) $request['id']);
        $size = $path !== null ? filesize($path) : false;

        if ($path === null || $size === false) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν υπάρχει φωτογραφία.'], 404);
        }

        // Ίδιο μοτίβο με το TicketsController::attachment(): readfile χωρίς
        // να φορτωθεί η εικόνα στη μνήμη. Ιδιωτική cache: αλλάζει το `v`.
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . $size);
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
