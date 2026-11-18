<?php

/**
 * (406) Σύνδεση σε δύο βήματα, από το «Το προφίλ μου → Ασφάλεια».
 *
 *   GET    /me/2fa                    κατάσταση και συσκευές
 *   POST   /me/2fa/start              νέο μυστικό για ρύθμιση (QR στον browser)
 *   POST   /me/2fa/confirm            {code}: ενεργοποίηση, εφεδρικοί ΜΙΑ φορά
 *   POST   /me/2fa/codes              {code}: νέοι εφεδρικοί
 *   POST   /me/2fa/disable            {password, code}: απενεργοποίηση
 *   DELETE /me/2fa/devices            ξέχνα όλες τις συσκευές
 *   DELETE /me/2fa/devices/{id}       ξέχνα μία
 *   GET    /team/2fa                  ποιος την έχει (διαχειριστής)
 *   DELETE /team/{id}/2fa             επαναφορά για όποιον έχασε κινητό (διαχειριστής)
 *
 * ΠΑΝΤΑ προαιρετική (απόφαση ιδιοκτήτη 16/11).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Infrastructure\TwoFactor;
use EnergyCRM\Infrastructure\UserSessions;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class TwoFactorController implements Controller
{
    public function __construct(private readonly TwoFactor $twoFactor)
    {
    }

    public function routes(): void
    {
        $me = [
            ['/me/2fa', 'GET', 'show', []],
            ['/me/2fa/start', 'POST', 'start', []],
            ['/me/2fa/confirm', 'POST', 'confirm', ['code' => ['type' => 'string', 'required' => true]]],
            ['/me/2fa/codes', 'POST', 'codes', ['code' => ['type' => 'string', 'required' => true]]],
            ['/me/2fa/disable', 'POST', 'disable', [
                'password' => ['type' => 'string', 'required' => true],
                'code'     => ['type' => 'string', 'required' => true],
            ]],
            ['/me/2fa/devices', 'DELETE', 'forgetAll', []],
            ['/me/2fa/devices/(?P<id>[a-f0-9]{12})', 'DELETE', 'forgetOne', []],
        ];

        foreach ($me as [$route, $method, $callback, $args]) {
            register_rest_route(Router::NAMESPACE, $route, [
                'methods'             => $method,
                'callback'            => [$this, $callback],
                'permission_callback' => Guards::crmUser(),
                'args'                => $args,
            ]);
        }

        register_rest_route(Router::NAMESPACE, '/team/2fa', [
            'methods'             => 'GET',
            'callback'            => [$this, 'team'],
            'permission_callback' => Guards::needs('manage_options'),
        ]);

        register_rest_route(Router::NAMESPACE, '/team/(?P<id>\d+)/2fa', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'reset'],
            'permission_callback' => Guards::needs('manage_options'),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->status();
    }

    public function start(WP_REST_Request $request): WP_REST_Response
    {
        $user = wp_get_current_user();

        if (! TwoFactor::available()) {
            return self::fail('Ο server δεν έχει την κρυπτογράφηση που χρειάζεται (libsodium).', 503);
        }

        if ($this->twoFactor->isEnabled($user->ID)) {
            return self::fail('Είναι ήδη ενεργή.', 409);
        }

        return new WP_REST_Response(['ok' => true] + $this->twoFactor->start($user), 200);
    }

    public function confirm(WP_REST_Request $request): WP_REST_Response
    {
        $user  = wp_get_current_user();
        $codes = $this->twoFactor->confirm($user->ID, (string) $request['code']);

        if ($codes === null) {
            return self::fail('Ο αριθμός δεν ταιριάζει. Γράψε αυτόν που δείχνει τώρα η εφαρμογή.', 422);
        }

        return $this->status(['codes' => $codes]);
    }

    public function codes(WP_REST_Request $request): WP_REST_Response
    {
        $user = wp_get_current_user();

        if (UserSessions::tooManyFailures($user->ID)) {
            return self::fail(UserSessions::TOO_MANY, 429);
        }

        if (! $this->twoFactor->verify($user->ID, (string) $request['code'])) {
            UserSessions::recordFailure($user->ID);

            return self::fail('Λάθος αριθμός από την εφαρμογή.', 422);
        }

        UserSessions::clearFailures($user->ID);

        return $this->status(['codes' => $this->twoFactor->newBackupCodes($user->ID)]);
    }

    public function disable(WP_REST_Request $request): WP_REST_Response
    {
        $user = wp_get_current_user();

        // (414) Ίδιος μετρητής με την αλλαγή κωδικού: χωρίς αυτόν, εδώ ο
        // κωδικός δοκιμαζόταν απεριόριστα.
        if (UserSessions::tooManyFailures($user->ID)) {
            return self::fail(UserSessions::TOO_MANY, 429);
        }

        if (! wp_check_password((string) $request['password'], $user->user_pass, $user->ID)) {
            UserSessions::recordFailure($user->ID);

            return self::fail('Λάθος κωδικός πρόσβασης.', 422);
        }

        if (! $this->twoFactor->verify($user->ID, (string) $request['code'])) {
            UserSessions::recordFailure($user->ID);

            return self::fail('Λάθος αριθμός από την εφαρμογή.', 422);
        }

        UserSessions::clearFailures($user->ID);
        $this->twoFactor->disable($user->ID);

        return $this->status();
    }

    public function forgetAll(WP_REST_Request $request): WP_REST_Response
    {
        $this->twoFactor->forgetDevices(get_current_user_id());

        return $this->status();
    }

    public function forgetOne(WP_REST_Request $request): WP_REST_Response
    {
        if (! $this->twoFactor->forgetDevice(get_current_user_id(), (string) $request['id'])) {
            return self::fail('Η συσκευή δεν βρέθηκε.', 404);
        }

        return $this->status();
    }

    public function team(WP_REST_Request $request): WP_REST_Response
    {
        $on = [];

        // Μία φορά, μόνο για τον διαχειριστή, πάνω σε λίγους χρήστες.
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        foreach (get_users(['fields' => 'ID', 'meta_key' => 'ecrm_2fa_secret']) as $id) {
            $on[] = (int) $id;
        }

        return new WP_REST_Response(['ok' => true, 'enabled' => $on], 200);
    }

    public function reset(WP_REST_Request $request): WP_REST_Response
    {
        $user = get_userdata((int) $request['id']);

        if (! $user instanceof WP_User) {
            return self::fail('Ο χρήστης δεν βρέθηκε.', 404);
        }

        $this->twoFactor->disable($user->ID);
        update_user_meta($user->ID, 'ecrm_2fa_reset', [
            'by' => get_current_user_id(),
            'at' => current_time('mysql'),
        ]);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /** @param array<string, mixed> $extra */
    private function status(array $extra = []): WP_REST_Response
    {
        $cookie = isset($_COOKIE[TwoFactor::COOKIE])
            ? sanitize_text_field(wp_unslash((string) $_COOKIE[TwoFactor::COOKIE]))
            : '';

        return new WP_REST_Response(
            ['ok' => true] + $extra + $this->twoFactor->status(wp_get_current_user(), $cookie),
            200
        );
    }

    private static function fail(string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => false, 'error' => $message], $status);
    }
}
