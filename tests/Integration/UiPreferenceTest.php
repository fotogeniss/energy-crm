<?php

/**
 * (353) Νέα εμφάνιση «Signature»: όσο είναι σε δοκιμή, μόνο ο διαχειριστής.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Infrastructure\UiPreference;
use WP_REST_Request;
use WP_User;

final class UiPreferenceTest extends IntegrationTestCase
{
    public function testTheAdministratorSwitchesItOnAndOff(): void
    {
        $admin = $this->administrator();
        wp_set_current_user($admin);

        self::assertSame(UiPreference::CLASSIC, UiPreference::forUser($admin), 'Προεπιλογή η κλασική.');
        self::assertSame(UiPreference::SIGNATURE, $this->post('signature')->get_data()['ui']);
        self::assertSame(UiPreference::SIGNATURE, UiPreference::forUser($admin));
        self::assertSame(UiPreference::CLASSIC, $this->post('classic')->get_data()['ui']);
    }

    public function testNobodyElseSeesItEvenWithAStoredValue(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        update_user_meta($seller, 'ecrm_ui', UiPreference::SIGNATURE);
        wp_set_current_user($seller);

        self::assertSame(UiPreference::CLASSIC, UiPreference::forUser($seller));
        self::assertSame(403, $this->post('signature')->get_status());
    }

    private function post(string $ui): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/ui');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode(['ui' => $ui]));

        return rest_do_request($request);
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
