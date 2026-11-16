<?php

/**
 * (406) Σύνδεση σε δύο βήματα από άκρη σε άκρη: ρύθμιση, έλεγχος, εφεδρικοί,
 * συσκευές, επαναφορά από τον διαχειριστή. Πάντα προαιρετική.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\LoginThrottle;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\TwoFactorLogin;
use EnergyCRM\Domain\Security\Totp;
use EnergyCRM\Infrastructure\TwoFactor;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class TwoFactorTest extends IntegrationTestCase
{
    private TwoFactor $twoFactor;

    private WP_User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (! TwoFactor::available()) {
            self::markTestSkipped('Χωρίς libsodium δεν ενεργοποιείται.');
        }

        $this->twoFactor = TwoFactor::make();
        $this->user      = new WP_User($this->makeCrmUser(Roles::SELLER));
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testNobodyHasItUntilTheyTurnItOn(): void
    {
        self::assertFalse($this->twoFactor->isEnabled($this->user->ID));

        $login = new TwoFactorLogin(new LoginThrottle());
        self::assertSame($this->user, $login->intercept($this->user), 'Χωρίς δύο βήματα, η σύνδεση δεν αλλάζει.');
    }

    public function testSetUpConfirmAndVerifyOnce(): void
    {
        $setup = $this->twoFactor->start($this->user);

        self::assertFalse($this->twoFactor->isEnabled($this->user->ID), 'Πριν το confirm δεν ισχύει.');
        self::assertNull($this->twoFactor->confirm($this->user->ID, '000000'));

        $codes = $this->twoFactor->confirm($this->user->ID, $this->now($setup['secret']));

        self::assertIsArray($codes);
        self::assertCount(8, $codes);
        self::assertTrue($this->twoFactor->isEnabled($this->user->ID));

        // Το μυστικό δεν αποθηκεύεται ως απλό κείμενο.
        $stored = (string) get_user_meta($this->user->ID, 'ecrm_2fa_secret', true);
        self::assertStringNotContainsString($setup['secret'], $stored);

        // Ο αριθμός της ενεργοποίησης δεν περνά ξανά (ίδιο βήμα).
        self::assertFalse($this->twoFactor->verify($this->user->ID, $this->now($setup['secret'])));
        self::assertFalse($this->twoFactor->verify($this->user->ID, '123456'));
    }

    public function testABackupCodeWorksOnce(): void
    {
        $codes = $this->enable();

        self::assertTrue($this->twoFactor->useBackup($this->user->ID, $codes[3]));
        self::assertFalse($this->twoFactor->useBackup($this->user->ID, $codes[3]));
        self::assertSame(7, $this->twoFactor->status($this->user)['backup_left']);
    }

    public function testARememberedDeviceIsForgottenWhenThePasswordChanges(): void
    {
        $this->enable();

        $cookie = $this->twoFactor->remember($this->user, 'Mozilla/5.0 (Windows NT 10.0) Chrome/120');

        self::assertTrue($this->twoFactor->isRemembered($this->user, $cookie));
        self::assertFalse($this->twoFactor->isRemembered($this->user, $cookie . 'x'));
        self::assertCount(1, $this->twoFactor->status($this->user, $cookie)['devices']);
        self::assertTrue($this->twoFactor->status($this->user, $cookie)['devices'][0]['current']);

        wp_set_password('Νέος-κωδικός-2026!', $this->user->ID);
        $fresh = new WP_User($this->user->ID);

        self::assertFalse($this->twoFactor->isRemembered($fresh, $cookie), 'Νέος κωδικός: η συσκευή ξεχνιέται.');
    }

    public function testTheRestFlowAndTheAdminReset(): void
    {
        wp_set_current_user($this->user->ID);

        $start = $this->call('POST', '/me/2fa/start');
        self::assertSame(200, $start->get_status());
        self::assertStringStartsWith('otpauth://totp/', (string) $start->get_data()['uri']);

        $bad = $this->call('POST', '/me/2fa/confirm', ['code' => '000000']);
        self::assertSame(422, $bad->get_status());

        $ok = $this->call('POST', '/me/2fa/confirm', ['code' => $this->now((string) $start->get_data()['secret'])]);
        self::assertSame(200, $ok->get_status());
        self::assertTrue($ok->get_data()['enabled']);
        self::assertCount(8, $ok->get_data()['codes']);

        // Ο πωλητής δεν βλέπει ούτε επαναφέρει τους άλλους.
        self::assertSame(403, $this->call('GET', '/team/2fa')->get_status());
        self::assertSame(403, $this->call('DELETE', '/team/' . $this->user->ID . '/2fa')->get_status());

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        self::assertContains($this->user->ID, $this->call('GET', '/team/2fa')->get_data()['enabled']);
        self::assertSame(200, $this->call('DELETE', '/team/' . $this->user->ID . '/2fa')->get_status());
        self::assertFalse($this->twoFactor->isEnabled($this->user->ID));
    }

    public function testDisablingNeedsThePasswordAndANumber(): void
    {
        wp_set_password('Σωστός-κωδικός-2026', $this->user->ID);
        $this->user = new WP_User($this->user->ID);
        $secret     = $this->enableReturningSecret();
        wp_set_current_user($this->user->ID);

        // Ο αριθμός μετράει από το ΕΠΟΜΕΝΟ βήμα: ο τωρινός χρησιμοποιήθηκε στην ενεργοποίηση.
        $next = Totp::code($secret, Totp::stepAt(time()) + 1);

        $wrong = $this->call('POST', '/me/2fa/disable', ['password' => 'λάθος', 'code' => $next]);
        self::assertSame(422, $wrong->get_status());
        self::assertTrue($this->twoFactor->isEnabled($this->user->ID));

        $ok = $this->call('POST', '/me/2fa/disable', ['password' => 'Σωστός-κωδικός-2026', 'code' => $next]);
        self::assertSame(200, $ok->get_status());
        self::assertFalse($this->twoFactor->isEnabled($this->user->ID));
    }

    /** @return list<string> */
    private function enable(): array
    {
        $setup = $this->twoFactor->start($this->user);
        $codes = $this->twoFactor->confirm($this->user->ID, $this->now($setup['secret']));
        self::assertIsArray($codes);

        return $codes;
    }

    private function enableReturningSecret(): string
    {
        $setup = $this->twoFactor->start($this->user);
        self::assertIsArray($this->twoFactor->confirm($this->user->ID, $this->now($setup['secret'])));

        return $setup['secret'];
    }

    private function now(string $secret): string
    {
        return Totp::code($secret, Totp::stepAt(time()));
    }

    /** @param array<string, mixed> $params */
    private function call(string $method, string $route, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/ecrm/v1' . $route);

        if ($params !== []) {
            $request->set_body_params($params);
        }

        return rest_do_request($request);
    }
}
