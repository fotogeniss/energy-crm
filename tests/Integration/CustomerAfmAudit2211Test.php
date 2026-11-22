<?php

/**
 * (444) Έλεγχος λογικής 22/11, πελάτες: το ΑΦΜ δεν σβήνεται όσο ο πελάτης
 * έχει αίτηση εκτός προχείρου, ούτε από την καρτέλα ούτε από τη φόρμα.
 *
 * Η «Αποθήκευση αλλαγών» της φόρμας δεν στέλνει κατάσταση, οπότε ο έλεγχος
 * εξόδου από πρόχειρο (418) δεν έτρεχε. Η καρτέλα πελάτη δεν τον είχε καθόλου.
 * Η αίτηση έφευγε μετά για υπογραφή με κενό το κουτί του ΑΦΜ.
 *
 * @kanonas K4
 * @kanonas K11
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Http\CustomersController;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class CustomerAfmAudit2211Test extends IntegrationTestCase
{
    private const AFM = '090003373';

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user($this->makeCrmUser(Roles::SELLER));
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testTheCardCannotEraseTheAfmOfACustomerWithALiveContract(): void
    {
        [$contractId, $customerId] = $this->draft();
        $this->setStatus($contractId, 'registration');

        $response = $this->send('PATCH', '/ecrm/v1/customers/' . $customerId, ['afm' => '']);

        self::assertSame(422, $response->get_status());
        self::assertSame(CustomersController::AFM_IN_USE, $response->get_data()['error'] ?? null);
        self::assertSame(self::AFM, $this->afmOf($customerId));
    }

    public function testTheCardCanEraseTheAfmWhileEverythingIsADraft(): void
    {
        [, $customerId] = $this->draft();

        $response = $this->send('PATCH', '/ecrm/v1/customers/' . $customerId, ['afm' => '']);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertSame('', $this->afmOf($customerId));
    }

    public function testSavingChangesInTheFormCannotEraseTheAfmEither(): void
    {
        [$contractId, $customerId] = $this->draft();
        $this->setStatus($contractId, 'registration');

        $response = $this->send('POST', '/ecrm/v1/contracts', ['contract_id' => $contractId, 'afm' => '']);

        self::assertSame(422, $response->get_status());
        self::assertSame('afm', $response->get_data()['field'] ?? null);
        self::assertSame(self::AFM, $this->afmOf($customerId));
    }

    /** @return array{0: int, 1: int} */
    private function draft(): array
    {
        $response = $this->send('POST', '/ecrm/v1/contracts', [
            'first_name' => 'Άννα',
            'last_name'  => 'Δοκιμή',
            'afm'        => self::AFM,
            'status'     => 'draft',
        ]);
        $data = $response->get_data();
        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));

        return [(int) $data['contract_id'], (int) $data['customer_id']];
    }

    private function setStatus(int $contractId, string $status): void
    {
        global $wpdb;

        $wpdb->update(Tables::name(Tables::CONTRACTS), ['status' => $status], ['id' => $contractId]);
    }

    private function afmOf(int $customerId): string
    {
        $row = (new CustomerRepository())->find($customerId, UserScope::forAdministrator(1));

        return (string) ($row['afm'] ?? '');
    }

    /** @param array<string, mixed> $params */
    private function send(string $method, string $path, array $params): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $path);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }
}
