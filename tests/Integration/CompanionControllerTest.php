<?php

/**
 * (326) «+ Αέριο / + Ρεύμα στον ίδιο πελάτη» μέσα από το πραγματικό REST.
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

final class CompanionControllerTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private int $partner;

    private int $provider;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->contracts = new ContractRepository();
        $this->partner   = $this->makeCrmUser();

        $wpdb->insert(Tables::name(Tables::PROVIDERS), ['slug' => 'ecrm-companion', 'name' => 'Πάροχος Δίδυμων']);
        $this->provider = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $this->provider);

        (new ProviderGrantRepository())->set($this->partner, [$this->provider]);
        wp_set_current_user($this->partner);
    }

    public function testAGasTwinIsCreatedWithTheCustomerAndTheElectricitySupplyNumber(): void
    {
        $this->program('gas');
        $power = $this->power();

        $show = $this->call('GET', $power);
        self::assertSame('gas', $show['energy']);
        self::assertTrue($show['available']);
        self::assertNull($show['existing']);

        $made = $this->call('POST', $power);
        self::assertTrue($made['ok']);
        self::assertFalse($made['existing']);

        $gas = $this->contracts->find((int) $made['contract_id'], UserScope::forSelf($this->partner));
        self::assertNotNull($gas);
        self::assertSame('gas', $gas['energy_type']);
        self::assertSame('draft', $gas['status']);
        self::assertSame($this->partner, (int) $gas['partner_user_id']);
        self::assertNull($gas['program_id']);
        self::assertNull($gas['signed_at']);
        self::assertSame('1 23456789-01', json_decode((string) $gas['extra_json'], true)['gas_power_supply_number']);
        self::assertNotSame('', (string) $gas['code']);
    }

    public function testASecondPressOpensTheSameTwinInsteadOfMakingAnother(): void
    {
        $this->program('gas');
        $power = $this->power();

        $first  = $this->call('POST', $power);
        $second = $this->call('POST', $power);

        self::assertTrue($second['existing']);
        self::assertSame($first['contract_id'], $second['contract_id']);
        self::assertSame((int) $first['contract_id'], $this->call('GET', $power)['existing']['id']);
    }

    public function testNoTwinWhenTheProviderSellsNoGas(): void
    {
        $power = $this->power();

        self::assertFalse($this->call('GET', $power)['available']);

        $response = rest_do_request(new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $power . '/companion'));
        self::assertSame(422, $response->get_status());
    }

    public function testSomeoneElsesContractIsNotFound(): void
    {
        $this->program('gas');
        $power = $this->power();

        wp_set_current_user($this->makeCrmUser());

        $response = rest_do_request(new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $power . '/companion'));
        self::assertSame(404, $response->get_status());
    }

    private function program(string $energy): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => $this->provider, 'name' => 'Πρόγραμμα ' . $energy, 'energy_type' => $energy, 'active' => 1,
        ]);
        self::assertGreaterThan(0, (int) $wpdb->insert_id);
    }

    private function power(): int
    {
        $customer = (new CustomerRepository())->create($this->customerData());

        $id = $this->contracts->create([
            'status' => 'presale', 'energy_type' => 'power', 'customer_id' => $customer,
            'provider_id' => $this->provider, 'supply_number' => '1 23456789-01',
            'extra_json' => wp_json_encode(['payment_method' => 'manual', 'agreed_power' => '25']),
        ], UserScope::forSelf($this->partner));
        self::assertGreaterThan(0, $id);

        return $id;
    }

    /** @return array<string, mixed> */
    private function call(string $method, int $id): array
    {
        $response = rest_do_request(new WP_REST_Request($method, '/ecrm/v1/contracts/' . $id . '/companion'));
        self::assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        /** @var array<string, mixed> $data */
        $data = $response->get_data();

        return $data;
    }
}
