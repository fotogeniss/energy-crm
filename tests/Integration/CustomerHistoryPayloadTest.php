<?php

/**
 * (346) GET /customers/{id}/history -- σχήμα απάντησης και ορατότητα.
 *
 * Ο ίδιος πελάτης με σύμβαση δύο διαφορετικών πωλητών: ο καθένας βλέπει στο
 * ιστορικό μόνο ό,τι κρέμεται από τις δικές του συμβάσεις και τις δικές του
 * εργασίες — ίδιος κανόνας με την υπόλοιπη καρτέλα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerNoteRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\TaskRepository;
use WP_REST_Request;

final class CustomerHistoryPayloadTest extends IntegrationTestCase
{
    public function testTheResponseCarriesExactlyTheKeysTheScreenReads(): void
    {
        $seller = $this->makeCrmUser();
        wp_set_current_user($seller);

        $customer = (new CustomerRepository())->create($this->customerData('444444445'));
        $contract = $this->ownedContract($seller, $customer);
        (new CustomerNoteRepository())->create($customer, $seller, 'Προτιμά απόγευμα');

        $data = $this->history($customer);

        self::assertSame(['ok', 'items', 'counts', 'truncated'], array_keys($data));
        self::assertSame(
            ['kind', 'tone', 'at', 'title', 'detail', 'contract_id', 'contract_ref', 'contract_code', 'actor'],
            array_keys($data['items'][0]),
            'Το σχήμα άλλαξε. Ψάξε ποιος το διαβάζει: public/assets/ecrm-view-customer-history.js'
        );
        self::assertSame(
            ['contract', 'sign', 'task', 'note', 'doc', 'edit', 'all'],
            array_keys($data['counts'])
        );
        self::assertContains($contract, array_column($data['items'], 'contract_id'));
    }

    public function testASellerSeesOnlyWhatHangsFromTheirOwnContractsAndTasks(): void
    {
        $mine   = $this->makeCrmUser();
        $theirs = $this->makeCrmUser();

        $customer = (new CustomerRepository())->create($this->customerData('555555556'));
        $myOwn    = $this->ownedContract($mine, $customer);
        $other    = $this->ownedContract($theirs, $customer);

        (new EventRepository())->record($other, $theirs, 'note', ['message' => 'μυστική σημείωση άλλου']);

        $tasks = new TaskRepository();
        $tasks->create([
            'customer_id' => $customer, 'assigned_to' => $mine, 'created_by' => $mine, 'title' => 'Η δική μου κλήση',
        ]);
        $tasks->create([
            'customer_id' => $customer, 'assigned_to' => $theirs, 'created_by' => $theirs,
            'title'       => 'Η κλήση του άλλου',
        ]);

        wp_set_current_user($mine);
        $items  = $this->history($customer)['items'];
        $titles = array_column($items, 'title');

        self::assertContains($myOwn, array_column($items, 'contract_id'));
        self::assertNotContains($other, array_column($items, 'contract_id'));
        self::assertContains('Εργασία: «Η δική μου κλήση»', $titles);
        self::assertNotContains('Εργασία: «Η κλήση του άλλου»', $titles);
        self::assertStringNotContainsString('μυστική', (string) wp_json_encode($items));
    }

    public function testAnUnreachableCustomerIsNotFound(): void
    {
        $owner    = $this->makeCrmUser();
        $stranger = $this->makeCrmUser();
        $customer = (new CustomerRepository())->create($this->customerData('666666667'));
        $this->ownedContract($owner, $customer);

        wp_set_current_user($stranger);
        $response = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/customers/' . $customer . '/history'));

        self::assertSame(404, $response->get_status());
    }

    private function ownedContract(int $partner, int $customer): int
    {
        $id = (new ContractRepository())->create([
            'status' => 'registration', 'energy_type' => 'power', 'customer_id' => $customer, 'category' => 'home',
        ], UserScope::forSelf($partner));
        self::assertGreaterThan(0, $id);

        (new EventRepository())->record($id, $partner, 'created');

        return $id;
    }

    /** @return array<string, mixed> */
    private function history(int $customer): array
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/customers/' . $customer . '/history'));
        self::assertSame(200, $response->get_status());

        /** @var array<string, mixed> $data */
        $data = $response->get_data();
        self::assertTrue($data['ok']);

        return $data;
    }
}
