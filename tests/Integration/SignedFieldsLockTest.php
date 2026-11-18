<?php

/**
 * (415) Υπογεγραμμένη αίτηση: ο πωλητής δεν αλλάζει είδος ενέργειας ή ΑΦΜ,
 * ούτε από τη φόρμα ούτε από την καρτέλα πελάτη. Ο διαχειριστής ναι.
 *
 * @kanonas K3
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class SignedFieldsLockTest extends IntegrationTestCase
{
    private const AFM = '090003373';

    private const OTHER_AFM = '094014201';

    private int $contractId;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $response = $this->call('POST', '/contracts', [
            'first_name'  => 'Υπογεγραμμένη',
            'last_name'   => 'Αίτηση',
            'afm'         => self::AFM,
            'energy_type' => 'power',
            'status'      => ContractStatus::Draft->value,
        ]);
        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        $this->contractId = (int) $response->get_data()['contract_id'];
        $this->customerId = (int) $response->get_data()['customer_id'];

        global $wpdb;
        $wpdb->update(
            Tables::name(Tables::CONTRACTS),
            ['signed_at' => current_time('mysql')],
            ['id' => $this->contractId]
        );
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testTheSellerCannotChangeTheEnergyType(): void
    {
        $response = $this->call('POST', '/contracts', ['contract_id' => $this->contractId, 'energy_type' => 'gas']);

        self::assertSame(422, $response->get_status());
        self::assertSame('energy_type', $response->get_data()['field']);
        self::assertSame('power', (string) $this->storedRow('contracts', $this->contractId)['energy_type']);
    }

    public function testResendingTheSameValuesStillSaves(): void
    {
        $response = $this->call('POST', '/contracts', [
            'contract_id' => $this->contractId,
            'energy_type' => 'power',
            'afm'         => self::AFM,
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    public function testTheAfmIsLockedInTheFormAndOnTheCustomerCard(): void
    {
        $form = $this->call('POST', '/contracts', ['contract_id' => $this->contractId, 'afm' => self::OTHER_AFM]);
        self::assertSame(422, $form->get_status());
        self::assertSame('afm', $form->get_data()['field']);

        $card = $this->call('PATCH', '/customers/' . $this->customerId, ['afm' => self::OTHER_AFM]);
        self::assertSame(422, $card->get_status());
        self::assertSame('afm', $card->get_data()['field']);
    }

    public function testTheAdministratorCanStillChangeIt(): void
    {
        wp_set_current_user($this->makeCrmUser('administrator'));

        $response = $this->call('POST', '/contracts', ['contract_id' => $this->contractId, 'energy_type' => 'gas']);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertSame('gas', (string) $this->storedRow('contracts', $this->contractId)['energy_type']);
    }

    /** @param array<string, mixed> $params */
    private function call(string $method, string $route, array $params): WP_REST_Response
    {
        $request = new WP_REST_Request($method, '/ecrm/v1' . $route);
        $request->set_body_params($params);

        return rest_do_request($request);
    }
}
