<?php

/**
 * (328) Πρόταση από λογαριασμό μέσα από το πραγματικό REST.
 *
 * Η ανάγνωση (/bill/read) καλεί το AI και δεν τρέχει εδώ· ελέγχεται μόνο ότι
 * χωρίς συναίνεση το αρχείο δεν φεύγει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\Tables;
use EnergyCRM\Providers\Persistence\ProviderGrantRepository;
use WP_REST_Request;

final class BillAdvisorControllerTest extends IntegrationTestCase
{
    private int $partner;

    private int $provider;

    private int $hidden;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->partner = $this->makeCrmUser();

        $wpdb->insert(Tables::name(Tables::PROVIDERS), ['slug' => 'ecrm-bill-seen', 'name' => 'Ορατός']);
        $this->provider = (int) $wpdb->insert_id;
        $wpdb->insert(Tables::name(Tables::PROVIDERS), ['slug' => 'ecrm-bill-hidden', 'name' => 'Κρυφός']);
        $this->hidden = (int) $wpdb->insert_id;

        (new ProviderGrantRepository())->set($this->partner, [$this->provider]);
        wp_set_current_user($this->partner);
    }

    public function testProgramsAreRankedCheapestFirstAndOnlyFromVisibleProviders(): void
    {
        $this->program($this->provider, 'Σταθερό', ['price_kwh' => 0.13, 'fixed_charge' => 5]);
        $this->program($this->provider, 'Πακέτο', [
            'pricing' => '{"kind":"package","package_kwh":1325,"supply_month":21.5,"excess_kwh":0.099}',
        ]);
        $this->program($this->provider, 'Χωρίς τιμή', []);
        $this->program($this->hidden, 'Κρυφό φθηνό', ['price_kwh' => 0.01, 'fixed_charge' => 0]);

        $out = $this->compare([
            'kwh' => 3770, 'energy' => 'power', 'category' => 'home', 'price_kwh' => 0.149, 'fixed_month' => 5,
        ]);

        self::assertTrue($out['ok']);
        self::assertSame(621.73, $out['current_annual']);

        $names = array_column($out['ranked'], 'name');
        self::assertNotContains('Κρυφό φθηνό', $names);
        // Πακέτο: 12 × 21,50 + (3770 − 1325) × 0,099 = 500,06 €.
        // Σταθερό: 3770 × 0,13 + 12 × 5 = 550,10 €. Φθηνότερο πρώτο.
        self::assertSame(['Πακέτο', 'Σταθερό'], array_values(array_intersect($names, ['Σταθερό', 'Πακέτο'])));
        self::assertContains('Χωρίς τιμή', array_column($out['unpriced'], 'name'));
    }

    public function testReadingRefusesWithoutTheCustomerConsent(): void
    {
        $response = rest_do_request(new WP_REST_Request('POST', '/ecrm/v1/bill/read'));

        self::assertSame(400, $response->get_status());
    }

    /**
     * @param array<string, mixed> $price
     */
    private function program(int $provider, string $name, array $price): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROGRAMS), array_merge([
            'provider_id' => $provider, 'name' => $name, 'energy_type' => 'power', 'category' => 'home',
            'price_type' => 'fixed', 'active' => 1,
        ], $price));
        self::assertGreaterThan(0, (int) $wpdb->insert_id);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function compare(array $params): array
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/bill/compare');

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status());

        return (array) $response->get_data();
    }
}
