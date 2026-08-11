<?php

/**
 * The five list queries behind the screens, pinned before they move.
 *
 * Characterisation: every assertion here describes ContractRepository::search(),
 * quickSearch(), countsByStatus(), expiring() and possibleDuplicates() exactly
 * as they stand, so that the split into ContractQueries can change one thing —
 * the name of what gets called — and nothing else.
 *
 * ## Why these five and why now
 *
 * Nothing named any of them anywhere in tests/. What coverage they had arrived
 * sideways, through the REST suite, and it only ever asked one question: does
 * the scope hold. That is the question most worth asking, but it is not the
 * only one these queries answer, and the rest was unguarded:
 *
 *   - countsByStatus() omits a status that has no rows rather than reporting
 *     zero. Every caller has to know that, and nothing said so.
 *   - quickSearch() and search() escape LIKE wildcards. A customer whose name
 *     contains a percent sign is not a hypothetical in a CRM that imports.
 *   - possibleDuplicates() ignores scope deliberately — it is the one query
 *     here that is *supposed* to see across the whole company. A future reader
 *     tidying up "the query that forgot its UserScope" would break the
 *     duplicate warning and nothing would have complained.
 *   - expiring() excludes drafts and cancellations, and both exclusions are a
 *     business rule sitting in a WHERE clause.
 *
 * The ΑΦΜ cases matter twice over. Randomised encryption means cu.afm never
 * equals itself, so both matching paths go through the blind index; a search
 * that quietly stops matching reads on screen as "no such customer", which is
 * indistinguishable from the truth.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;

final class ContractQueriesTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private CustomerRepository $customers;

    private int $alice;

    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->customers = new CustomerRepository();

        $this->alice = $this->makePartner();
        $this->bob   = $this->makePartner();
    }

    // --- countsByStatus ----------------------------------------------------

    public function testTheTabsCountEachStatusSeparately(): void
    {
        $this->contractFor($this->alice, ['status' => 'new']);
        $this->contractFor($this->alice, ['status' => 'new']);
        $this->contractFor($this->alice, ['status' => 'signed']);

        $counts = $this->contracts->countsByStatus(UserScope::forSelf($this->alice));

        self::assertSame(2, $counts['new']);
        self::assertSame(1, $counts['signed']);
    }

    /**
     * A status nobody is in is missing from the map, not present as zero.
     *
     * The counts come from a GROUP BY, which has no row to group. Every caller
     * therefore needs a null coalesce, and this is the only place that says so.
     */
    public function testAStatusWithNoContractsIsAbsentRatherThanZero(): void
    {
        $this->contractFor($this->alice, ['status' => 'new']);

        $counts = $this->contracts->countsByStatus(UserScope::forSelf($this->alice));

        self::assertArrayNotHasKey('cancelled', $counts);
    }

    public function testTheTabsNeverCountAnotherPartnersContracts(): void
    {
        $this->contractFor($this->alice, ['status' => 'new']);
        $this->contractFor($this->bob, ['status' => 'new']);

        $counts = $this->contracts->countsByStatus(UserScope::forSelf($this->alice));

        self::assertSame(1, $counts['new']);
    }

    /** The owner sees the company's total, which is the point of the role. */
    public function testAnAdministratorCountsEverybodys(): void
    {
        $this->contractFor($this->alice, ['status' => 'new']);
        $this->contractFor($this->bob, ['status' => 'new']);

        $counts = $this->contracts->countsByStatus(UserScope::forAdministrator($this->alice));

        self::assertSame(2, $counts['new']);
    }

    // --- search ------------------------------------------------------------

    public function testTheListFiltersByStatus(): void
    {
        $this->contractFor($this->alice, ['status' => 'new']);
        $signed = $this->contractFor($this->alice, ['status' => 'signed']);

        $rows = $this->contracts->search(UserScope::forSelf($this->alice), 'signed');

        self::assertCount(1, $rows);
        self::assertSame($signed, (int) $rows[0]['id']);
    }

    public function testTheListFindsAContractByItsCode(): void
    {
        $wanted = $this->contractFor($this->alice, ['status' => 'new', 'code' => 'ECRM-Q-1']);
        $this->contractFor($this->alice, ['status' => 'new', 'code' => 'ECRM-Q-2']);

        $rows = $this->contracts->search(UserScope::forSelf($this->alice), '', 'ECRM-Q-1');

        self::assertCount(1, $rows);
        self::assertSame($wanted, (int) $rows[0]['id']);
    }

    public function testTheListFindsAContractByTheCustomersSurname(): void
    {
        $wanted = $this->contractFor($this->alice, ['status' => 'new'], $this->customerData());
        $this->contractFor($this->alice, ['status' => 'new']);

        $rows = $this->contracts->search(UserScope::forSelf($this->alice), '', 'Παπαδόπουλος');

        self::assertCount(1, $rows);
        self::assertSame($wanted, (int) $rows[0]['id']);
    }

    /**
     * A percent sign is a character, not a wildcard.
     *
     * esc_like() is what stands between a search box and a query that returns
     * the whole table to whoever types one symbol.
     */
    public function testAPercentSignInTheSearchTermMatchesNothingRatherThanEverything(): void
    {
        $this->contractFor($this->alice, ['status' => 'new'], $this->customerData());

        $rows = $this->contracts->search(UserScope::forSelf($this->alice), '', '%');

        self::assertSame([], $rows);
    }

    // --- quickSearch -------------------------------------------------------

    /** An empty box returns before it reaches the database. */
    public function testTheGlobalSearchAnswersAnEmptyTermWithNothing(): void
    {
        $this->contractFor($this->alice, ['status' => 'new']);

        self::assertSame([], $this->contracts->quickSearch(UserScope::forSelf($this->alice), ''));
    }

    public function testTheGlobalSearchFindsBySupplyNumber(): void
    {
        $wanted = $this->contractFor($this->alice, [
            'status'        => 'new',
            'supply_number' => '50000000001',
        ]);
        $this->contractFor($this->alice, ['status' => 'new', 'supply_number' => '50000000002']);

        $rows = $this->contracts->quickSearch(UserScope::forSelf($this->alice), '50000000001');

        self::assertCount(1, $rows);
        self::assertSame($wanted, (int) $rows[0]['id']);
    }

    public function testTheGlobalSearchNeverReachesAnotherPartnersContract(): void
    {
        $this->contractFor($this->bob, ['status' => 'new', 'code' => 'ECRM-Q-BOB']);

        $rows = $this->contracts->quickSearch(UserScope::forSelf($this->alice), 'ECRM-Q-BOB');

        self::assertSame([], $rows);
    }

    /**
     * The whole ΑΦΜ still finds the customer once the column is ciphertext.
     *
     * Not through cu.afm — randomised encryption means that column never equals
     * itself — but through the blind index, which is why the index is there.
     */
    public function testTheGlobalSearchFindsAnEncryptedTaxNumberInFull(): void
    {
        $this->encryptionOn();

        $wanted = $this->contractFor($this->alice, ['status' => 'new'], $this->customerData('987654321'));

        $rows = $this->contracts->quickSearch(UserScope::forSelf($this->alice), '987654321');

        self::assertCount(1, $rows);
        self::assertSame($wanted, (int) $rows[0]['id']);
    }

    /** And what comes back is readable, not the stored ciphertext. */
    public function testTheGlobalSearchReturnsTheTaxNumberInPlaintext(): void
    {
        $this->encryptionOn();

        $this->contractFor($this->alice, ['status' => 'new'], $this->customerData('987654321'));

        $rows = $this->contracts->quickSearch(UserScope::forSelf($this->alice), '987654321');

        self::assertSame('987654321', (string) $rows[0]['afm']);
    }

    // --- expiring ----------------------------------------------------------

    public function testARenewalDueInsideTheWindowIsListed(): void
    {
        $wanted = $this->contractFor($this->alice, [
            'status'   => 'active',
            'end_date' => $this->daysFromToday(10),
        ]);

        $rows = $this->contracts->expiring(UserScope::forSelf($this->alice), 30);

        self::assertCount(1, $rows);
        self::assertSame($wanted, (int) $rows[0]['id']);
    }

    public function testARenewalDueBeyondTheWindowIsNot(): void
    {
        $this->contractFor($this->alice, [
            'status'   => 'active',
            'end_date' => $this->daysFromToday(90),
        ]);

        self::assertSame([], $this->contracts->expiring(UserScope::forSelf($this->alice), 30));
    }

    public function testAContractWithNoEndDateIsNeverUpForRenewal(): void
    {
        $this->contractFor($this->alice, ['status' => 'active']);

        self::assertSame([], $this->contracts->expiring(UserScope::forSelf($this->alice), 30));
    }

    /**
     * Neither a draft nor a cancellation is up for renewal.
     *
     * A business rule that lives in a WHERE clause: the screen would otherwise
     * chase the agent to renew something the customer walked away from.
     */
    public function testDraftsAndCancellationsAreLeftOutEvenWhenTheyExpire(): void
    {
        $this->contractFor($this->alice, ['status' => 'draft', 'end_date' => $this->daysFromToday(10)]);
        $this->contractFor($this->alice, ['status' => 'cancelled', 'end_date' => $this->daysFromToday(10)]);

        self::assertSame([], $this->contracts->expiring(UserScope::forSelf($this->alice), 30));
    }

    public function testTheRenewalListStaysInsideTheScope(): void
    {
        $this->contractFor($this->bob, ['status' => 'active', 'end_date' => $this->daysFromToday(10)]);

        self::assertSame([], $this->contracts->expiring(UserScope::forSelf($this->alice), 30));
    }

    // --- possibleDuplicates ------------------------------------------------

    /**
     * The duplicate check looks across the whole company, on purpose.
     *
     * This is the one query here that takes no UserScope, and the omission is
     * the feature: a second application for a supply a colleague already signed
     * is exactly the collision worth warning about. Anyone "fixing" this by
     * adding a scope would silence the warning without breaking a thing.
     */
    public function testADuplicateIsFoundEvenWhenItBelongsToAnotherPartner(): void
    {
        $theirs = $this->contractFor($this->bob, [
            'status'        => 'signed',
            'supply_number' => '50000000009',
        ]);

        $rows = $this->contracts->possibleDuplicates('', '50000000009');

        self::assertCount(1, $rows);
        self::assertSame($theirs, (int) $rows[0]['id']);
    }

    public function testTheContractBeingEditedIsNotItsOwnDuplicate(): void
    {
        $mine = $this->contractFor($this->alice, [
            'status'        => 'new',
            'supply_number' => '50000000010',
        ]);

        self::assertSame([], $this->contracts->possibleDuplicates('', '50000000010', $mine));
    }

    /** Too few digits to be a ΑΦΜ is not a search, it is a keystroke. */
    public function testAPartialTaxNumberIsNotTreatedAsACriterion(): void
    {
        $this->contractFor($this->alice, ['status' => 'new'], $this->customerData());

        self::assertSame([], $this->contracts->possibleDuplicates('12345', ''));
    }

    public function testWithNeitherATaxNumberNorASupplyNothingIsClaimed(): void
    {
        $this->contractFor($this->alice, ['status' => 'new'], $this->customerData());

        self::assertSame([], $this->contracts->possibleDuplicates('', ''));
    }

    // --- Fixtures ----------------------------------------------------------

    /**
     * A contract owned by $ownerId, optionally with a customer attached.
     *
     * Ownership comes from the scope rather than the data, because
     * partner_user_id is not writable — which is itself the subject of
     * ContractScopeTest::testOwnershipCannotBeSetByTheCaller.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $customer
     */
    private function contractFor(int $ownerId, array $data, array $customer = []): int
    {
        if ($customer !== []) {
            $data['customer_id'] = $this->customers->create($customer);
        }

        $contractId = $this->contracts->create($data, UserScope::forSelf($ownerId));

        self::assertGreaterThan(0, $contractId, 'The contract fixture was not inserted.');

        return $contractId;
    }

    /**
     * A date the given number of days from today, in site-local time.
     *
     * Local rather than UTC because expiring() compares against MySQL NOW().
     * The windows in these tests are wide enough that the difference cannot
     * decide an assertion — which is deliberate, since a test that flips at
     * midnight teaches nothing.
     */
    private function daysFromToday(int $days): string
    {
        return date('Y-m-d', strtotime('+' . $days . ' days'));
    }
}
