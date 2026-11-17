<?php

/**
 * (393) Σκλήρυνση γύρω από το CRM: λίστα χρηστών και XML-RPC κλειστά σε
 * ανώνυμους. (409) Κωδικοί εφαρμογής κλειστοί για όλους, και ο διαχειριστής.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Hardening;
use EnergyCRM\Access\Roles;
use WP_REST_Request;
use WP_User;

final class HardeningTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** (409) Ούτε ο διαχειριστής: με κωδικούς του «test» γράφτηκε το spam της 17/11. */
    public function testNobodyKeepsApplicationPasswords(): void
    {
        $hardening = new Hardening();

        self::assertFalse($hardening->appPasswordsFor(true, get_userdata($this->makeCrmUser(Roles::SELLER))));
        self::assertFalse($hardening->appPasswordsFor(true, get_userdata($this->makeCrmUser(Roles::PARTNER))));
        self::assertFalse($hardening->appPasswordsFor(true, get_userdata($this->administrator())));
        self::assertFalse(wp_is_application_passwords_available());
        self::assertFalse(wp_is_application_passwords_available_for_user($this->administrator()));
    }

    /** Τοπικά, για το tools/load-test-appwd.php, ξανανοίγουν με φίλτρο. */
    public function testTheLoadTestFilterStillReopensThem(): void
    {
        $open = static fn (): bool => true;
        add_filter('ecrm_app_passwords_for_everyone', $open);

        try {
            self::assertTrue((new Hardening())->appPasswordsAvailable(true));
            self::assertFalse((new Hardening())->appPasswordsAvailable(false));
        } finally {
            remove_filter('ecrm_app_passwords_for_everyone', $open);
        }
    }

    public function testTheFilterIsActuallyRegistered(): void
    {
        self::assertNotFalse(has_filter('wp_is_application_passwords_available_for_user'));
        self::assertNotFalse(has_filter('wp_is_application_passwords_available'));
        self::assertFalse(apply_filters('xmlrpc_enabled', true));
    }

    public function testAnonymousCannotListUsers(): void
    {
        wp_set_current_user(0);

        $list = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users'));
        $one  = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users/' . $this->administrator()));

        self::assertSame(401, $list->get_status());
        self::assertSame(401, $one->get_status());
    }

    public function testASignedInUserStillReachesTheirOwnRecord(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        self::assertSame(200, rest_do_request(new WP_REST_Request('GET', '/wp/v2/users/me'))->get_status());
    }

    public function testOnlyUserRoutesAreAffected(): void
    {
        self::assertTrue(Hardening::isUserRoute('/wp/v2/users'));
        self::assertTrue(Hardening::isUserRoute('/wp/v2/users/12'));
        self::assertFalse(Hardening::isUserRoute('/wp/v2/usersx'));
        self::assertFalse(Hardening::isUserRoute('/ecrm/v1/me/security'));
    }

    private function administrator(): int
    {
        $userId = $this->makePartner();
        $user   = get_user_by('id', $userId);
        self::assertInstanceOf(WP_User::class, $user);
        $user->set_role('administrator');

        return $userId;
    }
}
