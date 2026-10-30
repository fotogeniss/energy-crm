<?php

/**
 * (333) GET /cross-sell και «Όχι τώρα» μέσα από το πραγματικό REST.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use WP_REST_Request;

final class CrossSellControllerTest extends IntegrationTestCase
{
    public function testActivePowerWithoutGasIsListedUntilSnoozed(): void
    {
        $partner = $this->makeCrmUser();
        wp_set_current_user($partner);

        $lonely = $this->customer('111111118');
        $paired = $this->customer('222222226');

        $this->contract($partner, $lonely, 'power', 'active');
        $this->contract($partner, $paired, 'power', 'active');
        $this->contract($partner, $paired, 'gas', 'presale');

        $ids = array_column($this->list('gas'), 'id');
        self::assertContains($lonely, $ids);
        self::assertNotContains($paired, $ids, 'Αίτηση αερίου σε εξέλιξη: η πώληση έγινε.');

        $snooze = new WP_REST_Request('POST', '/ecrm/v1/customers/' . $lonely . '/cross-sell-snooze');
        $snooze->set_body_params(['want' => 'gas', 'note' => 'δεν έχει δίκτυο']);
        self::assertSame(200, rest_do_request($snooze)->get_status());

        self::assertNotContains($lonely, array_column($this->list('gas'), 'id'));
    }

    public function testACancelledGasContractStillLeavesAnOpportunityAndADraftIsOffered(): void
    {
        $partner = $this->makeCrmUser();
        wp_set_current_user($partner);

        $customer = $this->customer('333333334');
        $this->contract($partner, $customer, 'power', 'active');
        $this->contract($partner, $customer, 'gas', 'cancelled');
        $draft = $this->contract($partner, $customer, 'gas', 'draft');

        $row = array_values(array_filter($this->list('gas'), static fn ($r) => $r['id'] === $customer))[0];
        self::assertSame($draft, $row['draft_id']);
    }

    private function customer(string $afm): int
    {
        return (new CustomerRepository())->create($this->customerData($afm));
    }

    private function contract(int $partner, int $customer, string $energy, string $status): int
    {
        $id = (new ContractRepository())->create([
            'status' => $status, 'energy_type' => $energy, 'customer_id' => $customer, 'category' => 'home',
        ], UserScope::forSelf($partner));
        self::assertGreaterThan(0, $id);

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function list(string $want): array
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/cross-sell');
        $request->set_query_params(['want' => $want]);
        $data = rest_do_request($request)->get_data();
        self::assertTrue($data['ok']);

        return $data['rows'];
    }
}
