<?php

/**
 * (335) Λήξεις: πρόταση φθηνότερου προγράμματος, κατανάλωση, ανανέωση με αυτό.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Providers\Persistence\ProviderGrantRepository;
use WP_REST_Request;

final class RenewalOfferTest extends IntegrationTestCase
{
    public function testTheExpiringContractGetsACheaperProgramAndRenewsOnIt(): void
    {
        global $wpdb;

        $partner = $this->makeCrmUser();
        $wpdb->insert(Tables::name(Tables::PROVIDERS), ['slug' => 'ecrm-renew-offer', 'name' => 'Ανανέωση']);
        $provider = (int) $wpdb->insert_id;
        $dear     = $this->program($provider, 'Ακριβό', 0.20);
        $cheap    = $this->program($provider, 'Φθηνό', 0.12);

        (new ProviderGrantRepository())->set($partner, [$provider]);
        wp_set_current_user($partner);

        $customer = (new CustomerRepository())->create($this->customerData());
        $contract = (new ContractRepository())->create([
            'status' => 'active', 'energy_type' => 'power', 'category' => 'home', 'customer_id' => $customer,
            'provider_id' => $provider, 'program_id' => $dear,
            'start_date' => gmdate('Y-m-d', strtotime('-11 months')),
            'end_date'   => gmdate('Y-m-d', strtotime('+20 days')),
            'term_months' => 12,
        ], UserScope::forSelf($partner));

        self::assertNull($this->row($contract)['annual_kwh']);

        $kwh = new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $contract . '/annual-kwh');
        $kwh->set_body_params(['kwh' => 3000]);
        self::assertSame(200, rest_do_request($kwh)->get_status());

        $row = $this->row($contract);
        self::assertSame(3000.0, $row['annual_kwh']);
        self::assertArrayNotHasKey('extra_json', $row);
        self::assertSame('cheaper', $row['offer']['state']);
        self::assertSame($cheap, $row['offer']['best']['id']);
        self::assertSame(240.0, $row['offer']['saving']);

        $renew = new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $contract . '/renew');
        $renew->set_body_params(['program_id' => $cheap]);
        $made = rest_do_request($renew)->get_data();
        self::assertTrue($made['ok']);

        $draft = (new ContractRepository())->find((int) $made['contract_id'], UserScope::forSelf($partner));
        self::assertSame($cheap, (int) $draft['program_id']);
    }

    private function program(int $provider, string $name, float $price): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => $provider, 'name' => $name, 'energy_type' => 'power', 'category' => 'home',
            'price_type' => 'fixed', 'price_kwh' => $price, 'fixed_charge' => 5, 'active' => 1,
            'price_updated' => gmdate('Y-m-d'),
        ]);

        return (int) $wpdb->insert_id;
    }

    /** @return array<string, mixed> */
    private function row(int $contract): array
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/renewals');
        $request->set_query_params(['days' => 60]);
        $rows = rest_do_request($request)->get_data()['rows'];

        return array_values(array_filter($rows, static fn ($r) => (int) $r['id'] === $contract))[0];
    }
}
