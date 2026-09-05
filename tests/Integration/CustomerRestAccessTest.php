<?php

/**
 * One partner's customer, asked for by another -- over HTTP, through /customers.
 *
 * `/contracts` has ContractRestAccessTest proving the scope survives the trip
 * through the route: the permission_callback, the args schema, the
 * ScopeResolver reading the current user, and the controller turning a null
 * row into an answer. `/customers` never had the same proof, even though it
 * returns the same class of data -- ΑΦΜ, ΑΔΤ, phone, address -- and even
 * though CustomerRepository is already correctly scoped (see
 * EncryptedCustomerColumnsTest and its own docblock). This file is that
 * missing proof, mirroring ContractRestAccessTest's shape on purpose: three
 * routes -- show, index, check -- instead of one.
 *
 * A customer row has no owner column of its own (CustomerRepository's own
 * docblock: "reachability is defined as -- there exists a contract for this
 * customer that the actor may see"). Fixtures below build exactly that: a
 * customer, then a contract linking it to a partner, the same two-step shape
 * EncryptedCustomerColumnsTest already uses.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class CustomerRestAccessTest extends IntegrationTestCase
{
    private CustomerRepository $customers;

    private int $alice;

    private int $bob;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customers  = new CustomerRepository();
        $this->alice      = $this->makeCrmUser(Roles::SELLER);
        $this->bob        = $this->makeCrmUser(Roles::SELLER);
        $this->customerId = $this->customers->create($this->customerData());

        self::assertGreaterThan(0, $this->customerId);

        $this->giveCustomerAContract($this->customerId, $this->alice);
    }

    protected function tearDown(): void
    {
        // The current user is global; left set, it would decide the next test.
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * Same discipline as the contracts version: a 403 would confirm the row
     * exists, which is half of what an enumeration attack is after. So both
     * the status and the body are compared against a genuinely absent id.
     */
    public function testAnotherPartnerCannotTellTheCustomerApartFromOneThatDoesNotExist(): void
    {
        wp_set_current_user($this->bob);

        $somebodyElses = $this->get('/ecrm/v1/customers/' . $this->customerId);
        $neverExisted  = $this->get('/ecrm/v1/customers/999999999');

        self::assertSame(404, $somebodyElses->get_status());
        self::assertSame($neverExisted->get_status(), $somebodyElses->get_status());
        self::assertSame($neverExisted->get_data(), $somebodyElses->get_data());
    }

    /** The owner reaches their own customer through the same route without trouble. */
    public function testTheOwningPartnerCanReadTheCustomer(): void
    {
        wp_set_current_user($this->alice);

        $response = $this->get('/ecrm/v1/customers/' . $this->customerId);

        self::assertSame(200, $response->get_status());
        self::assertSame('123456789', $response->get_data()['customer']['afm'] ?? null);
    }

    /**
     * Καρτέλα πελάτη (247, Στάδιο 1) -- ξεχωριστό route από το show(), ίδιος
     * κίνδυνος: επιστρέφει ΑΦΜ, διεύθυνση, τηλέφωνα. Ιδια πειθαρχία με το
     * παραπάνω: 404, όχι 403, ώστε ένα ξένο ΑΦΜ να μη διαφέρει από ένα id που
     * δεν υπάρχει καθόλου.
     */
    public function testAnotherPartnerCannotTellTheCustomerCardApartFromOneThatDoesNotExist(): void
    {
        wp_set_current_user($this->bob);

        $somebodyElses = $this->get('/ecrm/v1/customers/' . $this->customerId . '/card');
        $neverExisted  = $this->get('/ecrm/v1/customers/999999999/card');

        self::assertSame(404, $somebodyElses->get_status());
        self::assertSame($neverExisted->get_status(), $somebodyElses->get_status());
        self::assertSame($neverExisted->get_data(), $somebodyElses->get_data());
    }

    /**
     * Ο ιδιοκτήτης βλέπει την καρτέλα του, με τη σύμβαση που του φτιάξαμε
     * στο setUp() να εμφανίζεται μέσα στο /card -- όχι μόνο τον πελάτη.
     */
    public function testTheOwningPartnerCanReadTheCustomerCard(): void
    {
        wp_set_current_user($this->alice);

        $response = $this->get('/ecrm/v1/customers/' . $this->customerId . '/card');
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertSame('123456789', $data['customer']['afm'] ?? null);
        self::assertSame(1, $data['kpi']['contracts_count'] ?? null);
        self::assertCount(1, $data['contracts'] ?? []);
    }

    /**
     * 247, Στάδιο 2: ένας ξένος συνεργάτης δεν πρέπει να μπορεί να γράψει
     * σημείωση σε πελάτη που δεν βλέπει καν -- ακόμα κι αν μαντέψει σωστά
     * το id.
     */
    public function testAnotherPartnerCannotAddANoteToTheCustomer(): void
    {
        wp_set_current_user($this->bob);

        $response = $this->send('POST', '/ecrm/v1/customers/' . $this->customerId . '/notes', [
            'body' => 'Δοκιμή εισβολής.',
        ]);

        self::assertSame(404, $response->get_status());
    }

    /** Ο ιδιοκτήτης προσθέτει σημείωση και τη βλέπει αμέσως πίσω. */
    public function testTheOwningPartnerCanAddANote(): void
    {
        wp_set_current_user($this->alice);

        $response = $this->send('POST', '/ecrm/v1/customers/' . $this->customerId . '/notes', [
            'body' => 'Καλεί μετά τις 17:00.',
        ]);

        self::assertSame(200, $response->get_status());
        self::assertSame('Καλεί μετά τις 17:00.', $response->get_data()['notes'][0]['body'] ?? null);
    }

    /**
     * 247, Στάδιο 2: ίδιος κίνδυνος με τη σημείωση -- ένας ξένος συνεργάτης
     * δεν πρέπει να αλλάζει το τηλέφωνο επικοινωνίας πελάτη που δεν βλέπει.
     */
    public function testAnotherPartnerCannotChangeTheContactPhone(): void
    {
        wp_set_current_user($this->bob);

        $response = $this->send('PATCH', '/ecrm/v1/customers/' . $this->customerId . '/contact-phone', [
            'contact_phone' => '6944111222',
        ]);

        self::assertSame(404, $response->get_status());
    }

    /** Ο ιδιοκτήτης αλλάζει το τηλέφωνο επικοινωνίας και το βλέπει στην απάντηση. */
    public function testTheOwningPartnerCanSetTheContactPhone(): void
    {
        wp_set_current_user($this->alice);

        $response = $this->send('PATCH', '/ecrm/v1/customers/' . $this->customerId . '/contact-phone', [
            'contact_phone' => '6944111222',
        ]);

        self::assertSame(200, $response->get_status());
        self::assertSame('6944111222', $response->get_data()['contact_phone'] ?? null);
    }

    /** The customer book is a list of one partner's rows, not the whole table. */
    public function testAnotherPartnersCustomerIsAbsentFromTheList(): void
    {
        $this->customers->create($this->customerData('223456789'));
        $mine = $this->customers->create($this->customerData('323456789'));
        $this->giveCustomerAContract($mine, $this->bob);

        wp_set_current_user($this->bob);

        $response = $this->get('/ecrm/v1/customers');

        self::assertSame(200, $response->get_status());
        self::assertNotContains($this->customerId, $this->idsIn($response->get_data()['rows']));
        self::assertContains($mine, $this->idsIn($response->get_data()['rows']));
    }

    /**
     * `scope=team` does not widen reach on its own -- it only stops narrowing
     * to `self`. An unrelated partner asking for the team scope must not get
     * back rows that were never in their downline to begin with.
     */
    public function testTheTeamScopeDoesNotLeakAcrossUnrelatedPartners(): void
    {
        wp_set_current_user($this->bob);

        $response = $this->get('/ecrm/v1/customers', ['scope' => 'team']);

        self::assertSame(200, $response->get_status());
        self::assertNotContains($this->customerId, $this->idsIn($response->get_data()['rows']));
    }

    /**
     * The duplicate-check must not tell an unrelated partner that this ΑΦΜ is
     * already on file -- that would be the same class of leak the audit found
     * in /contracts/duplicate, just reached from the other controller.
     */
    public function testCheckNeverRevealsAnotherPartnersCustomer(): void
    {
        wp_set_current_user($this->bob);

        $response = $this->get('/ecrm/v1/customers/check', ['afm' => '123456789']);

        self::assertSame(200, $response->get_status());
        self::assertSame([], $response->get_data()['matches']);
    }

    /** And the owner does see their own match, so the emptiness above is scope, not a broken query. */
    public function testCheckFindsTheOwningPartnersOwnCustomer(): void
    {
        wp_set_current_user($this->alice);

        $response = $this->get('/ecrm/v1/customers/check', ['afm' => '123456789']);

        self::assertSame(200, $response->get_status());
        self::assertNotSame([], $response->get_data()['matches']);
    }

    /**
     * The control that makes every refusal above mean something.
     *
     * A test that has only ever seen a 404 or an empty list cannot tell "the
     * scope refused" from "this route is broken and refuses everyone" -- and
     * the second one passes just as green. So here is the same customer, over
     * the same route, with one thing changed: the caller now has the owner in
     * their downline. It must succeed.
     */
    public function testAManagerWithTheOwnerInTheirDownlineDoesGetThrough(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);

        update_user_meta($this->alice, NetworkRepository::PARENT_META, $manager);
        (new NetworkRepository())->rebuild($this->alice);

        wp_set_current_user($manager);

        $response = $this->get('/ecrm/v1/customers/' . $this->customerId);

        self::assertSame(
            200,
            $response->get_status(),
            'A manager with the owner in their downline must be able to reach the customer.'
        );
    }

    private function giveCustomerAContract(int $customerId, int $partnerId): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'customer_id'     => $customerId,
            'partner_user_id' => $partnerId,
            'status'          => 'new',
            'supply_number'   => '1' . $customerId . '901',
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, string> $query
     */
    private function get(string $path, array $query = []): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', $path);

        foreach ($query as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }

    /**
     * @param array<string, string> $params
     */
    private function send(string $method, string $path, array $params = []): WP_REST_Response
    {
        $request = new WP_REST_Request($method, $path);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private function idsIn(array $rows): array
    {
        return array_values(array_map(static fn (array $row): int => (int) $row['id'], $rows));
    }
}
