<?php

/**
 * (434) Υποψήφιος από το site με ΑΦΜ πελάτη που υπάρχει ήδη: η μετατροπή
 * δένει την αίτηση στον ίδιο πελάτη, δεν φτιάχνει δεύτερο.
 *
 * Πριν, κάθε μετατροπή έφτιαχνε νέα γραμμή πελάτη με το ίδιο ΑΦΜ (K11), και
 * τα στοιχεία του «δίδυμου» άλλαζαν ελεύθερα ακόμη κι όταν ο υπάρχων είχε
 * υπογεγραμμένη αίτηση (K3, 428).
 *
 * @kanonas K11
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\LeadRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;

final class LeadConversionReusesCustomerTest extends IntegrationTestCase
{
    private const AFM = '090003373';

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testASiteLeadWithAKnownAfmJoinsTheExistingCustomer(): void
    {
        global $wpdb;

        $seller = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($seller);

        $first = new WP_REST_Request('POST', '/ecrm/v1/contracts');
        $first->set_body_params([
            'first_name' => 'Υπάρχων',
            'last_name'  => 'Πελάτης',
            'afm'        => self::AFM,
            'street'     => 'Ερμού',
            'status'     => 'draft',
        ]);
        $saved = rest_do_request($first)->get_data();
        $customerId = (int) $saved['customer_id'];
        self::assertGreaterThan(0, $customerId);

        $leadId = (new LeadRepository())->create(
            ['name' => 'Από το site', 'phone' => '6912345678'],
            UserScope::forSelf($seller)
        );
        $wpdb->update(
            Tables::name(Tables::LEADS),
            ['site_data' => (string) wp_json_encode(['afm' => '090 003 373', 'street' => 'Σταδίου 5'])],
            ['id' => $leadId]
        );

        $response = rest_do_request(new WP_REST_Request('POST', '/ecrm/v1/leads/' . $leadId . '/convert'));
        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        $contract = $this->storedRow('contracts', (int) $response->get_data()['contract_id']);
        self::assertSame($customerId, (int) $contract['customer_id'], 'Φτιάχτηκε δεύτερος πελάτης με το ίδιο ΑΦΜ.');
        $customer = (new CustomerRepository())->find($customerId, UserScope::forAdministrator(1));
        self::assertSame('Ερμού', (string) ($customer['street'] ?? ''), 'Τα στοιχεία του υπάρχοντος δεν πατιούνται.');
    }
}
