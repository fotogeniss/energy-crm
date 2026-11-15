<?php

/**
 * (388) «Το προφίλ μου → Ασφάλεια»: αλλαγή κωδικού και συνδέσεις.
 * docs/UI-PROFILE-SECURITY.html.
 *
 * Στη βάση δοκιμών δεν υπάρχει cookie, οπότε δεν υπάρχει «τρέχουσα»
 * σύνδεση: κάθε σύνδεση που φτιάχνει το τεστ μετρά ως «άλλη».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Infrastructure\UserSessions;
use WP_Application_Passwords;
use WP_REST_Request;
use WP_REST_Response;
use WP_Session_Tokens;

final class ProfileSecurityTest extends IntegrationTestCase
{
    private const OLD = 'Arxiko-Kleidi-2026';

    private const NEW = 'Thalassa.Vouno.9';

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Χωρίς headers στη γραμμή εντολών.
        add_filter('send_auth_cookies', '__return_false');

        $this->user = $this->makeCrmUser(Roles::SELLER);
        wp_set_password(self::OLD, $this->user);
        wp_set_current_user($this->user);
        delete_transient('ecrm_pwfail_' . $this->user);
    }

    protected function tearDown(): void
    {
        remove_filter('send_auth_cookies', '__return_false');
        delete_transient('ecrm_pwfail_' . $this->user);

        parent::tearDown();
    }

    public function testTheRightCurrentPasswordChangesItAndClosesTheOtherSessions(): void
    {
        $this->session('Mozilla/5.0 (Linux; Android 14) Chrome/131.0 Mobile Safari/537.36', '176.58.10.20');
        $this->session('Mozilla/5.0 (Windows NT 10.0) Chrome/131.0 Safari/537.36', '85.74.12.200');

        $response = $this->change(self::OLD, self::NEW);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertSame(2, $response->get_data()['closed']);
        self::assertTrue(wp_check_password(self::NEW, get_userdata($this->user)->user_pass, $this->user));
        self::assertSame([], WP_Session_Tokens::get_instance($this->user)->get_all());
        self::assertNotSame('', $response->get_data()['password_changed_at']);
    }

    /**
     * (393) Ο κωδικός εφαρμογής δεν σβήνει μόνος του με την αλλαγή κωδικού·
     * είναι ο τρόπος να μείνει μέσα όποιος έκλεψε τον λογαριασμό.
     */
    public function testChangingThePasswordAlsoRevokesApplicationPasswords(): void
    {
        WP_Application_Passwords::create_new_application_password($this->user, ['name' => 'κάποιο script']);

        self::assertCount(1, WP_Application_Passwords::get_user_application_passwords($this->user));

        self::assertSame(200, $this->change(self::OLD, self::NEW)->get_status());
        self::assertSame([], WP_Application_Passwords::get_user_application_passwords($this->user));
    }

    public function testAWrongCurrentPasswordChangesNothing(): void
    {
        $response = $this->change('Lathos-Kleidi-1', self::NEW);

        self::assertSame(400, $response->get_status());
        self::assertTrue(wp_check_password(self::OLD, get_userdata($this->user)->user_pass, $this->user));
    }

    public function testFiveWrongTriesLockItForAWhile(): void
    {
        for ($i = 0; $i < UserSessions::MAX_FAILS; $i++) {
            $this->change('Lathos-Kleidi-' . $i, self::NEW);
        }

        // Ούτε με τον σωστό τωρινό κωδικό, όσο κρατά η αναμονή.
        self::assertSame(429, $this->change(self::OLD, self::NEW)->get_status());
        self::assertTrue(wp_check_password(self::OLD, get_userdata($this->user)->user_pass, $this->user));
    }

    public function testAWeakNewPasswordIsRefusedWithTheReason(): void
    {
        $response = $this->change(self::OLD, '1234567890');

        self::assertSame(400, $response->get_status());
        self::assertStringContainsString('συνηθισμένους', (string) $response->get_data()['error']);
    }

    public function testTheSessionsAreListedWithDeviceAndHalfTheIp(): void
    {
        $this->session('Mozilla/5.0 (Windows NT 10.0) Chrome/131.0 Safari/537.36', '85.74.12.200');

        $data = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/me/security'))->get_data();

        self::assertCount(1, $data['sessions']);
        self::assertSame('Chrome, Windows', $data['sessions'][0]['device']);
        self::assertSame('85.74.•••.•••', $data['sessions'][0]['ip']);
        self::assertFalse($data['sessions'][0]['current']);
        self::assertSame(64, strlen($data['sessions'][0]['id']));
    }

    public function testOneSessionCanBeClosed(): void
    {
        $this->session('Mozilla/5.0 (Windows NT 10.0) Chrome/131.0 Safari/537.36', '85.74.12.200');
        $this->session('Mozilla/5.0 (Linux; Android 14) Chrome/131.0 Mobile Safari/537.36', '176.58.10.20');

        $before = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/me/security'))->get_data()['sessions'];
        $id     = $before[0]['id'];

        $response = rest_do_request(new WP_REST_Request('DELETE', '/ecrm/v1/me/sessions/' . $id));

        self::assertSame(200, $response->get_status());
        self::assertCount(1, $response->get_data()['sessions']);
        self::assertNotSame($id, $response->get_data()['sessions'][0]['id']);
    }

    public function testNobodyElsesSessionCanBeClosed(): void
    {
        $other = $this->makeCrmUser(Roles::SELLER);
        WP_Session_Tokens::get_instance($other)->create(time() + HOUR_IN_SECONDS);
        $id = (string) array_key_first((array) get_user_meta($other, 'session_tokens', true));

        $response = rest_do_request(new WP_REST_Request('DELETE', '/ecrm/v1/me/sessions/' . $id));

        self::assertSame(404, $response->get_status());
        self::assertCount(1, WP_Session_Tokens::get_instance($other)->get_all());
    }

    private function change(string $current, string $new): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/me/password');
        $request->set_param('current', $current);
        $request->set_param('new', $new);

        return rest_do_request($request);
    }

    /** Μια σύνδεση όπως τη γράφει το WordPress στο login. */
    private function session(string $ua, string $ip): void
    {
        // Το create() τα διαβάζει από το $_SERVER, μετά από κάθε φίλτρο.
        $saved = [$_SERVER['HTTP_USER_AGENT'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null];

        $_SERVER['HTTP_USER_AGENT'] = $ua;
        $_SERVER['REMOTE_ADDR']     = $ip;
        WP_Session_Tokens::get_instance($this->user)->create(time() + HOUR_IN_SECONDS);

        foreach (['HTTP_USER_AGENT', 'REMOTE_ADDR'] as $i => $key) {
            if ($saved[$i] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $saved[$i];
            }
        }
    }
}
