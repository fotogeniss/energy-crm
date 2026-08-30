<?php

/**
 * CustomerRepository::update() actually enforces scope in the write, not
 * only in a check that runs before it.
 *
 * Found during the internal bug review of 30/08. The method read
 * `isReachable()` (scoped, through the same join `find()` uses) and then
 * wrote with a plain `$wpdb->update(..., ['id' => $customerId])` -- no
 * scope condition anywhere in the write itself. Nothing in that shape stops
 * the write from reaching a row the check no longer covers by the time the
 * write runs, and the columns at stake are ΑΦΜ, ΑΔΤ, address, phone --
 * exactly the ones CustomerRestAccessTest already proves are scoped on
 * read. A write path that trusted a prior read instead of re-asserting
 * scope at the point that matters is the same shape of bug closed for
 * `ContractLifecycle::moveTo()` in (177)/(178), just on a table that has to
 * express scope through a join to `contracts` rather than its own column.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;

final class CustomerUpdateScopeTest extends IntegrationTestCase
{
    private CustomerRepository $customers;

    private int $alice;

    private int $bob;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customers  = new CustomerRepository();
        $this->alice      = $this->makePartner();
        $this->bob        = $this->makePartner();
        $this->customerId = $this->customers->create($this->customerData());

        self::assertGreaterThan(0, $this->customerId);

        $this->giveCustomerAContract($this->customerId, $this->alice);
    }

    /** An unrelated partner cannot rewrite a customer's data, and nothing moves trying. */
    public function testAnotherPartnerCannotUpdateTheCustomer(): void
    {
        $updated = $this->customers->update(
            $this->customerId,
            UserScope::forSelf($this->bob),
            ['city' => 'Πάτρα']
        );

        self::assertFalse($updated, 'Ο μη-ιδιοκτήτης συνεργάτης δεν πρέπει να μπορεί να γράψει στη σύμβαση.');

        $stored = $this->storedRow(Tables::CUSTOMERS, $this->customerId);

        self::assertSame(
            'Θεσσαλονίκη',
            $stored['city'],
            'Η γραμμή άλλαξε παρόλο που η ενημέρωση έπρεπε να απορριφθεί εκτός scope.'
        );
    }

    /** The owning partner updates their own customer without trouble. */
    public function testTheOwningPartnerCanUpdateTheCustomer(): void
    {
        $updated = $this->customers->update(
            $this->customerId,
            UserScope::forSelf($this->alice),
            ['city' => 'Πάτρα']
        );

        self::assertTrue($updated, (string) wp_json_encode($this->storedRow(Tables::CUSTOMERS, $this->customerId)));

        $stored = $this->storedRow(Tables::CUSTOMERS, $this->customerId);
        self::assertSame('Πάτρα', $stored['city']);
    }

    /** Administrators are not scoped by the join -- the hierarchy decides commission, not the right to look. */
    public function testAnAdministratorCanUpdateAnyCustomer(): void
    {
        $admin = $this->makePartner();

        $updated = $this->customers->update(
            $this->customerId,
            UserScope::forAdministrator($admin),
            ['city' => 'Ηράκλειο']
        );

        self::assertTrue($updated);

        $stored = $this->storedRow(Tables::CUSTOMERS, $this->customerId);
        self::assertSame('Ηράκλειο', $stored['city']);
    }

    private function giveCustomerAContract(int $customerId, int $partnerId): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'customer_id'     => $customerId,
            'partner_user_id' => $partnerId,
            'status'          => 'new',
            'supply_number'   => '1' . $customerId . '902',
        ]);

        return (int) $wpdb->insert_id;
    }
}
