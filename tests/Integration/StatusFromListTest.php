<?php

/**
 * (384) Αλλαγή κατάστασης από τη λίστα. docs/UI-STATUS-INLINE.html.
 *
 * Η λίστα (`GET /contracts`) φέρει σε κάθε γραμμή τις επιτρεπτές επόμενες,
 * ίδιες με της καρτέλας· κενές σε όποιον δεν αλλάζει καταστάσεις. Η αλλαγή
 * (`POST /contracts/{id}/status`) απαντά με τις νέες επόμενες, ώστε η γραμμή
 * να ξαναγραφτεί επιτόπου χωρίς νέα φόρτωση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use WP_REST_Request;
use WP_User;

final class StatusFromListTest extends IntegrationTestCase
{
    private int $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($this->seller);
    }

    public function testEachRowCarriesTheSameNextStepsAsTheCard(): void
    {
        $id = $this->contract('presale');

        $row  = $this->listRow($id);
        $card = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/contracts/' . $id))->get_data();

        self::assertSame($card['allowed_next'], $row['allowed_next']);
        self::assertContains('registration', $row['allowed_next']);
        self::assertContains('cancelled', $row['allowed_next']);
        self::assertNotContains('presale', $row['allowed_next']);
        self::assertArrayNotHasKey('extra_json', $row, 'Το extra_json δεν φεύγει ποτέ προς τον browser.');
    }

    public function testACancelledRowHasNowhereToGo(): void
    {
        self::assertSame([], $this->listRow($this->contract('cancelled'))['allowed_next']);
    }

    public function testWithoutTheCapabilityTheBadgeStaysPlain(): void
    {
        $id   = $this->contract('presale');
        $user = wp_get_current_user();

        self::assertInstanceOf(WP_User::class, $user);
        $user->add_cap(Capability::CHANGE_STATUS, false);

        self::assertSame([], $this->listRow($id)['allowed_next']);
    }

    public function testTheChangeAnswersWithTheNextSteps(): void
    {
        $id = $this->contract('presale');

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $id . '/status');
        $request->set_param('status', 'pending_issue');
        $response = rest_do_request($request);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));
        self::assertSame('pending_issue', $data['status']);
        self::assertNotContains('pending_issue', $data['allowed_next']);
        self::assertContains('presale', $data['allowed_next'], 'Η «Αναίρεση» γυρνά στην προηγούμενη.');
        self::assertSame($data['allowed_next'], $this->listRow($id)['allowed_next']);
    }

    private function contract(string $status): int
    {
        $customerId = (new CustomerRepository())->create($this->customerData());
        $id         = (new ContractRepository())->create(
            ['status' => $status, 'customer_id' => $customerId, 'energy_type' => 'power'],
            UserScope::forSelf($this->seller)
        );

        self::assertGreaterThan(0, $id, 'Το fixture της σύμβασης δεν μπήκε.');

        return $id;
    }

    /** @return array<string, mixed> */
    private function listRow(int $id): array
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/contracts'));

        self::assertSame(200, $response->get_status());

        foreach ($response->get_data()['rows'] as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        self::fail('Η αίτηση ' . $id . ' δεν είναι στη λίστα.');
    }
}
