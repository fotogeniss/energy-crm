<?php

/**
 * (331) GET /programs/{id}/script μέσα από το πραγματικό REST.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\Tables;
use EnergyCRM\Providers\Persistence\ProviderGrantRepository;
use WP_REST_Request;

final class SalesScriptControllerTest extends IntegrationTestCase
{
    public function testTheScriptOfAVisibleProgram(): void
    {
        global $wpdb;

        $partner = $this->makeCrmUser();
        $wpdb->insert(Tables::name(Tables::PROVIDERS), ['slug' => 'ecrm-script', 'name' => 'Σενάριο']);
        $provider = (int) $wpdb->insert_id;
        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => $provider, 'name' => 'Σταθερό 12', 'energy_type' => 'power', 'category' => 'home',
            'price_type' => 'fixed', 'price_kwh' => 0.15, 'fixed_charge' => 5, 'active' => 1,
            'exit_fees' => '[100,100,50,50,50,50,20,20,20,10,10,0]',
        ]);
        $program = (int) $wpdb->insert_id;

        (new ProviderGrantRepository())->set($partner, [$provider]);
        wp_set_current_user($partner);

        $request = new WP_REST_Request('GET', '/ecrm/v1/programs/' . $program . '/script');
        $request->set_query_params(['payment_method' => 'standing_order']);
        $data = rest_do_request($request)->get_data();

        self::assertTrue($data['ok']);
        self::assertStringContainsString('0,150 €/kWh', $data['plain']);
        self::assertStringContainsString('100 € τους 2 πρώτους μήνες', $data['plain']);
        self::assertStringContainsString('εγγύηση είναι 0 €', $data['plain']);

        (new ProviderGrantRepository())->set($partner, []);
        $hidden = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/programs/' . $program . '/script'));
        self::assertSame(404, $hidden->get_status());
    }
}
