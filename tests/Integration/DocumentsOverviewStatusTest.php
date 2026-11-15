<?php

/**
 * (391) Η κατάσταση αλλάζει και από την οθόνη «Έγγραφα»: κάθε γραμμή του
 * `GET /documents-overview` φέρει τις επιτρεπτές επόμενες, ίδιες με της
 * λίστας αιτήσεων, και το `extra_json` δεν φεύγει προς τον browser.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;

final class DocumentsOverviewStatusTest extends IntegrationTestCase
{
    public function testEachRowCarriesTheNextStatuses(): void
    {
        global $wpdb;

        $seller = $this->makeCrmUser(Roles::SELLER);
        $id     = (new ContractRepository())->create(
            [
                'status'      => 'presale',
                'energy_type' => 'power',
                'customer_id' => (new CustomerRepository())->create($this->customerData()),
            ],
            UserScope::forSelf($seller)
        );

        self::assertGreaterThan(0, $id);

        $wpdb->insert(Tables::name(Tables::FILES), [
            'contract_id' => $id,
            'doc_kind'    => 'id_card',
            'filename'    => 'tautotita.jpg',
            'mime'        => 'image/jpeg',
            'path'        => '/nowhere/tautotita.jpg',
            'protected'   => 1,
        ]);

        wp_set_current_user($seller);

        $response = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/documents-overview'));

        self::assertSame(200, $response->get_status());

        $rows = array_values(array_filter(
            $response->get_data()['rows'],
            static fn (array $r): bool => (int) $r['id'] === $id
        ));

        self::assertCount(1, $rows);
        self::assertContains('registration', $rows[0]['allowed_next']);
        self::assertNotContains('presale', $rows[0]['allowed_next']);
        self::assertArrayNotHasKey('extra_json', $rows[0]);
    }
}
