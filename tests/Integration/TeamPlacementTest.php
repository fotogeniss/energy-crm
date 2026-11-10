<?php

/**
 * (352c) «Χωρίς ομάδα»: ποιοι φαίνονται, πού μπαίνουν, ποιος το κάνει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class TeamPlacementTest extends IntegrationTestCase
{
    public function testOnlyCrmUsersWithoutAManagerAreListed(): void
    {
        $admin    = $this->administrator();
        $orphan   = $this->makeCrmUser(Roles::SELLER);
        $placed   = $this->makeCrmUser(Roles::SELLER);
        $disabled = $this->makeCrmUser(Roles::BACKOFFICE);
        update_user_meta($placed, TeamRepository::PARENT_META, $admin);
        update_user_meta($disabled, TeamRepository::DISABLED_META, '1');

        wp_set_current_user($admin);
        $ids = array_column($this->call('GET', '/ecrm/v1/team/orphans')->get_data()['members'], 'id');

        self::assertContains($orphan, $ids);
        self::assertNotContains($placed, $ids);
        self::assertNotContains($disabled, $ids, 'Η «Αφαίρεση» αφήνει χωρίς ομάδα· δεν ξαναβγαίνει εδώ.');
        self::assertNotContains($admin, $ids);
    }

    public function testTheAdministratorTakesHimIntoHisTeamOrUnderAPartner(): void
    {
        $admin   = $this->administrator();
        $partner = $this->makeCrmUser(Roles::PARTNER);
        $first   = $this->makeCrmUser(Roles::SELLER);
        $second  = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($admin);

        self::assertSame(200, $this->place($first, 0)->get_status());
        self::assertSame(200, $this->place($second, $partner)->get_status());

        self::assertSame($admin, (int) get_user_meta($first, TeamRepository::PARENT_META, true));
        self::assertSame($partner, (int) get_user_meta($second, TeamRepository::PARENT_META, true));
    }

    public function testNotUnderAMemberOfHisOwnTeamNorTwice(): void
    {
        $admin = $this->administrator();
        $boss  = $this->makeCrmUser(Roles::PARTNER);
        $below = $this->makeCrmUser(Roles::PARTNER);
        update_user_meta($below, TeamRepository::PARENT_META, $boss);
        wp_set_current_user($admin);

        self::assertSame(422, $this->place($boss, $below)->get_status(), 'Κύκλος στο δέντρο.');
        self::assertSame(409, $this->place($below, 0)->get_status(), 'Έχει ήδη ομάδα.');
    }

    public function testOnlyAPartnerCanBeTheNewManager(): void
    {
        $admin  = $this->administrator();
        $seller = $this->makeCrmUser(Roles::SELLER);
        $orphan = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($admin);

        self::assertSame(422, $this->place($orphan, $seller)->get_status());
        self::assertSame('', (string) get_user_meta($orphan, TeamRepository::PARENT_META, true));
    }

    public function testAPartnerCannotDoIt(): void
    {
        $partner = $this->makeCrmUser(Roles::PARTNER);
        $orphan  = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($partner);

        self::assertSame(403, $this->call('GET', '/ecrm/v1/team/orphans')->get_status());
        self::assertSame(403, $this->place($orphan, 0)->get_status());
    }

    /** Από τη φόρμα «Add User» του wp-admin: στην ομάδα του διαχειριστή που τον έφτιαξε. */
    public function testAUserMadeInWordPressJoinsTheTeamOfWhoMadeHim(): void
    {
        $admin  = $this->administrator();
        $seller = $this->makeCrmUser(Roles::SELLER);
        $other  = $this->administrator();
        wp_set_current_user($admin);

        do_action('edit_user_created_user', $seller, 'both');
        do_action('edit_user_created_user', $other, 'both');

        self::assertSame($admin, (int) get_user_meta($seller, TeamRepository::PARENT_META, true));
        self::assertSame('', (string) get_user_meta($other, TeamRepository::PARENT_META, true));
    }

    private function place(int $member, int $manager): WP_REST_Response
    {
        return $this->call('POST', '/ecrm/v1/team/' . $member . '/place', ['manager_id' => $manager]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function call(string $method, string $route, array $body = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $route);

        if ($body !== []) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) wp_json_encode($body));
        }

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
