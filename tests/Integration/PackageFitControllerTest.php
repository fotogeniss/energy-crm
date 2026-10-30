<?php

/**
 * (332) GET /programs/{id}/package-fit μέσα από το πραγματικό REST.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Domain\Pricing\ProtergiaPrices2026;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Providers\Persistence\ProviderGrantRepository;
use WP_REST_Request;

final class PackageFitControllerTest extends IntegrationTestCase
{
    public function testTheWrongSizeIsToldAndTheRightOneOffered(): void
    {
        global $wpdb;

        $partner = $this->makeCrmUser();
        $wpdb->insert(Tables::name(Tables::PROVIDERS), ['slug' => 'ecrm-fit', 'name' => 'Πακέτα']);
        $provider = (int) $wpdb->insert_id;

        $small  = $this->program($provider, 'protergia_pic_b_s1');
        $medium = $this->program($provider, 'protergia_pic_b_m1');

        (new ProviderGrantRepository())->set($partner, [$provider]);
        wp_set_current_user($partner);

        $request = new WP_REST_Request('GET', '/ecrm/v1/programs/' . $small . '/package-fit');
        $request->set_query_params(['kwh' => 3770]);
        $data = rest_do_request($request)->get_data();

        self::assertTrue($data['applies']);
        self::assertFalse($data['fits']);
        self::assertSame('M 99,90', $data['reference']['label']);
        self::assertSame($medium, $data['reference']['program_id']);
        self::assertSame(600.0, $data['settlement']);
    }

    private function program(int $provider, string $code): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => $provider, 'name' => $code, 'code' => $code, 'energy_type' => 'power',
            'category' => 'business', 'price_type' => 'fixed', 'active' => 1,
            'pricing' => (string) json_encode(ProtergiaPrices2026::all()[$code]['pricing']),
        ]);

        return (int) $wpdb->insert_id;
    }
}
