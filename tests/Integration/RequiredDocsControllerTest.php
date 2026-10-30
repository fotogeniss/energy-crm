<?php

/**
 * (341) GET /docs/required -- η λίστα του «Ελέγχου πριν την αποστολή».
 *
 * Φυλάει ότι λέει ό,τι θα ζητήσει αργότερα η πύλη πληρωμής (ίδιο
 * `ECRM_Docs::required_for()`), και ότι τα ήδη ανεβασμένα έγγραφα μιας
 * αίτησης φαίνονται μόνο σε όποιον βλέπει την αίτηση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class RequiredDocsControllerTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testTheListIsTheOneTheGateUses(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $data = $this->call(['activation_type' => 'change_provider', 'energy_type' => 'power'])->get_data();

        self::assertSame(['id_card', 'provider_bill'], array_column($data['required'], 'kind'));
        self::assertSame(\ECRM_Docs::label('provider_bill'), $data['required'][1]['label']);
        self::assertSame([], $data['present']);
    }

    public function testMobileNeverAsksForE9(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $power  = array_column($this->call(['activation_type' => 'new_connection', 'energy_type' => 'power'])
            ->get_data()['required'], 'kind');
        $mobile = array_column($this->call(['activation_type' => 'new_connection', 'energy_type' => 'mobile'])
            ->get_data()['required'], 'kind');

        self::assertContains('e9', $power);
        self::assertNotContains('e9', $mobile);
    }

    public function testUploadedKindsShowOnlyForSomeoneWhoSeesTheContract(): void
    {
        global $wpdb;

        $owner = $this->makeCrmUser(Roles::SELLER);
        $other = $this->makeCrmUser(Roles::SELLER);

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $owner,
            'status'          => 'draft',
            'energy_type'     => 'power',
        ]);
        $contract = (int) $wpdb->insert_id;
        $wpdb->insert(Tables::name(Tables::FILES), [
            'contract_id' => $contract,
            'doc_kind'    => 'id_card',
            'filename'    => 'id.jpg',
        ]);

        $query = ['activation_type' => 'change_provider', 'energy_type' => 'power', 'contract_id' => $contract];

        wp_set_current_user($owner);
        self::assertSame(['id_card'], $this->call($query)->get_data()['present']);

        wp_set_current_user($other);
        self::assertSame([], $this->call($query)->get_data()['present']);
    }

    public function testAnonymousIsRefused(): void
    {
        self::assertSame(401, $this->call([])->get_status());
    }

    /**
     * @param array<string, mixed> $query
     */
    private function call(array $query): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/docs/required');
        $request->set_query_params($query);

        return rest_do_request($request);
    }
}
