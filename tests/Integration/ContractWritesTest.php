<?php

/**
 * The writes that had no net: reassign, deleteMany, assignCode and the two
 * lookups the bulk screens are built on.
 *
 * Characterisation, written before ContractRepository comes apart further, and
 * worth having on its own terms. A grep found that none of these was named
 * anywhere in tests/. What the REST suite covered was the refusal path — a
 * seller gets a 403 — never what happens on the way through.
 *
 * ## Why reassign() first
 *
 * partner_user_id is deliberately absent from ContractRepository::WRITABLE, so
 * that ownership can only change through this one audited method and never as a
 * side effect of a save. It is also the column that decides who gets paid. That
 * combination — a single guarded door in front of the commission — had no test
 * at all, which means nothing was stopping the guard from being removed.
 *
 * The assertions here therefore come in pairs: not only that the call returns
 * false, but that the row did not move. A boolean can be wrong on its own; a
 * row that changed owner while the method said no is the actual damage, and it
 * is invisible on screen until payout.
 *
 * ## What the bulk pair is really for
 *
 * reachableAmong() decides what a bulk action touches, and it drops ids rather
 * than refusing them: a stale selection must not block the rest of the batch.
 * That is a deliberate choice and a dangerous one to reverse by accident, so
 * both halves are pinned — that the outsider is dropped, and that everything
 * else still goes through.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\Tables;

final class ContractWritesTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private EventRepository $events;

    private int $alice;

    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events    = new EventRepository();
        $this->contracts = new ContractRepository(null, null, $this->events);
        $this->alice     = $this->makePartner();
        $this->bob       = $this->makePartner();
    }

    // --- reassign ----------------------------------------------------------

    public function testAManagerMovesAContractToSomebodyInTheirTeam(): void
    {
        $manager    = $this->makePartner();
        $contractId = $this->contractOf($this->alice);
        $team       = UserScope::forTeam($manager, [$this->alice, $this->bob]);

        self::assertTrue($this->contracts->reassign($contractId, $this->bob, $team));
        self::assertSame($this->bob, $this->ownerOnDisk($contractId));
    }

    /**
     * Handing a contract to somebody outside the team is refused — and the
     * contract stays exactly where it was.
     *
     * The second assertion is the one that matters. A method that returned
     * false after moving the row would look correct from the screen and pay the
     * wrong person.
     */
    public function testAContractCannotBeHandedToSomebodyOutsideTheScope(): void
    {
        $contractId = $this->contractOf($this->alice);
        $outsider   = $this->makePartner();

        self::assertFalse(
            $this->contracts->reassign($contractId, $outsider, UserScope::forSelf($this->alice))
        );
        self::assertSame($this->alice, $this->ownerOnDisk($contractId));
    }

    public function testAContractOutsideTheScopeCannotBeMovedAtAll(): void
    {
        $contractId = $this->contractOf($this->bob);

        self::assertFalse(
            $this->contracts->reassign($contractId, $this->alice, UserScope::forSelf($this->alice))
        );
        self::assertSame($this->bob, $this->ownerOnDisk($contractId));
    }

    /** The owner may hold on to it: nothing changes, and that is not a failure. */
    public function testMovingAContractToItsCurrentOwnerReportsSuccess(): void
    {
        $contractId = $this->contractOf($this->alice);

        self::assertTrue(
            $this->contracts->reassign($contractId, $this->alice, UserScope::forSelf($this->alice))
        );
        self::assertSame($this->alice, $this->ownerOnDisk($contractId));
    }

    /** The owner of the company can move anything to anyone. */
    public function testAnAdministratorIsNotLimitedByTheTree(): void
    {
        $contractId = $this->contractOf($this->bob);
        $admin      = UserScope::forAdministrator($this->makePartner());

        self::assertTrue($this->contracts->reassign($contractId, $this->alice, $admin));
        self::assertSame($this->alice, $this->ownerOnDisk($contractId));
    }

    // --- audit trail (εύρημα ελέγχου ασφαλείας/λογικής, 26/08/2026) --------

    /**
     * Η reassign() άλλαζε partner_user_id χωρίς κανένα αντίγραφο στο events --
     * σε αντίθεση με κάθε αλλαγή κατάστασης, που καταγράφεται πάντα. Ποιος
     * είχε τη σύμβαση πριν δεν ήταν ανιχνεύσιμο πουθενά.
     */
    public function testReassigningRecordsAnEventOnTheContract(): void
    {
        $contractId = $this->contractOf($this->alice);
        $team       = UserScope::forTeam($this->alice, [$this->alice, $this->bob]);

        self::assertTrue($this->contracts->reassign($contractId, $this->bob, $team));

        $events = $this->events->forContract($contractId);

        self::assertNotSame([], $events, 'Η ανάθεση δεν άφησε κανένα ίχνος.');
        self::assertSame('reassigned', $events[0]['type']);
        self::assertStringContainsString('από', $events[0]['message']);
        self::assertStringContainsString('σε', $events[0]['message']);
    }

    /** Μια αρνημένη ανάθεση δεν πρέπει να αφήνει ίχνος -- τίποτα δεν κινήθηκε. */
    public function testARefusedReassignRecordsNoEvent(): void
    {
        $contractId = $this->contractOf($this->alice);
        $outsider   = $this->makePartner();

        self::assertFalse(
            $this->contracts->reassign($contractId, $outsider, UserScope::forSelf($this->alice))
        );
        self::assertSame([], $this->events->forContract($contractId));
    }

    /** handOver() μετακινεί πολλές συμβάσεις μαζί -- καθεμιά παίρνει το δικό της γεγονός. */
    public function testHandOverRecordsAnEventOnEveryMovedContract(): void
    {
        $manager = $this->makePartner();
        $team    = UserScope::forTeam($manager, [$this->alice, $this->bob]);

        $first  = $this->contractOf($this->alice);
        $second = $this->contractOf($this->alice);

        self::assertSame(2, $this->contracts->handOver($this->alice, $this->bob, $team));

        foreach ([$first, $second] as $contractId) {
            $events = $this->events->forContract($contractId);
            self::assertNotSame([], $events, 'Η μεταφορά χαρτοφυλακίου δεν άφησε ίχνος στη σύμβαση ' . $contractId);
            self::assertSame('reassigned', $events[0]['type']);
        }
    }

    // --- assignCode --------------------------------------------------------

    public function testTheCodeCarriesTheProvidersOwnPrefix(): void
    {
        $contractId = $this->contractOf($this->alice, ['provider_id' => $this->makeProvider()]);

        $code = $this->contracts->assignCode($contractId, UserScope::forSelf($this->alice));

        self::assertStringStartsWith('ECRM-TEST-PROVIDER-', $code);
        self::assertSame($code, (string) $this->storedRow(Tables::CONTRACTS, $contractId)['code']);
    }

    /** A contract with no provider yet still gets a code, under the generic prefix. */
    public function testAContractWithoutAProviderFallsBackToApp(): void
    {
        $contractId = $this->contractOf($this->alice);

        $code = $this->contracts->assignCode($contractId, UserScope::forSelf($this->alice));

        self::assertStringStartsWith('APP-', $code);
    }

    public function testNoCodeIsStampedOnSomebodyElsesContract(): void
    {
        $contractId = $this->contractOf($this->bob);

        self::assertSame('', $this->contracts->assignCode($contractId, UserScope::forSelf($this->alice)));

        // Cast rather than assertNull: whether an unset code is NULL or '' is
        // the column's business, and this test is about neither.
        self::assertSame('', (string) $this->storedRow(Tables::CONTRACTS, $contractId)['code']);
    }

    // --- reachableAmong ----------------------------------------------------

    public function testABulkSelectionKeepsOnlyWhatTheActorMayTouch(): void
    {
        $mine   = $this->contractOf($this->alice);
        $theirs = $this->contractOf($this->bob);

        $rows = $this->contracts->reachableAmong([$mine, $theirs], UserScope::forSelf($this->alice));

        self::assertCount(1, $rows);
        self::assertSame($mine, (int) $rows[0]['id']);
    }

    /**
     * An id that no longer exists is dropped, not refused.
     *
     * A selection goes stale the moment a colleague deletes something. Refusing
     * the whole batch would make the screen unusable for everyone else in it.
     */
    public function testAStaleSelectionDoesNotBlockTheRestOfTheBatch(): void
    {
        $mine = $this->contractOf($this->alice);

        $rows = $this->contracts->reachableAmong([$mine, 999999999], UserScope::forSelf($this->alice));

        self::assertCount(1, $rows);
        self::assertSame($mine, (int) $rows[0]['id']);
    }

    public function testTheSameContractSelectedTwiceComesBackOnce(): void
    {
        $mine = $this->contractOf($this->alice);

        $rows = $this->contracts->reachableAmong([$mine, $mine], UserScope::forSelf($this->alice));

        self::assertCount(1, $rows);
    }

    public function testAnEmptySelectionReachesNothing(): void
    {
        $this->contractOf($this->alice);

        self::assertSame([], $this->contracts->reachableAmong([], UserScope::forSelf($this->alice)));
    }

    // --- deleteMany --------------------------------------------------------

    public function testABulkDeleteRemovesTheContractsInScope(): void
    {
        $first  = $this->contractOf($this->alice);
        $second = $this->contractOf($this->alice);

        self::assertSame(2, $this->contracts->deleteMany([$first, $second], UserScope::forSelf($this->alice)));
        self::assertFalse($this->existsOnDisk($first));
        self::assertFalse($this->existsOnDisk($second));
    }

    /** Somebody else's contract is not counted and not removed. */
    public function testABulkDeleteSkipsWhatIsOutsideTheScope(): void
    {
        $mine   = $this->contractOf($this->alice);
        $theirs = $this->contractOf($this->bob);

        self::assertSame(1, $this->contracts->deleteMany([$mine, $theirs], UserScope::forSelf($this->alice)));
        self::assertTrue($this->existsOnDisk($theirs));
    }

    public function testABulkDeleteOfNothingRemovesNothing(): void
    {
        $mine = $this->contractOf($this->alice);

        self::assertSame(0, $this->contracts->deleteMany([], UserScope::forSelf($this->alice)));
        self::assertTrue($this->existsOnDisk($mine));
    }

    // --- delete ------------------------------------------------------------

    public function testAContractInScopeIsDeleted(): void
    {
        $contractId = $this->contractOf($this->alice);

        self::assertTrue($this->contracts->delete($contractId, UserScope::forSelf($this->alice)));
        self::assertFalse($this->existsOnDisk($contractId));
    }

    public function testSomebodyElsesContractSurvivesADeleteAttempt(): void
    {
        $contractId = $this->contractOf($this->bob);

        self::assertFalse($this->contracts->delete($contractId, UserScope::forSelf($this->alice)));
        self::assertTrue($this->existsOnDisk($contractId));
    }

    // --- ownerId -----------------------------------------------------------

    public function testTheOwnerIsReportedForAContractInScope(): void
    {
        $contractId = $this->contractOf($this->alice);

        self::assertSame(
            $this->alice,
            $this->contracts->ownerId($contractId, UserScope::forSelf($this->alice))
        );
    }

    /**
     * Outside the scope the answer is null, not the owner.
     *
     * Otherwise the method would confirm who a stranger's customer belongs to,
     * which is the same disclosure the read path is careful to avoid.
     */
    public function testTheOwnerOfSomebodyElsesContractIsNotDisclosed(): void
    {
        $contractId = $this->contractOf($this->bob);

        self::assertNull($this->contracts->ownerId($contractId, UserScope::forSelf($this->alice)));
    }

    // --- Fixtures ----------------------------------------------------------

    /**
     * A contract owned by $ownerId.
     *
     * Ownership arrives through the scope, never through the data: that is what
     * ContractScopeTest::testOwnershipCannotBeSetByTheCaller pins, and writing
     * the fixture any other way would quietly test something else.
     *
     * @param array<string, mixed> $data
     */
    private function contractOf(int $ownerId, array $data = []): int
    {
        $contractId = $this->contracts->create(
            $data + ['status' => 'new', 'energy_type' => 'power'],
            UserScope::forSelf($ownerId)
        );

        self::assertGreaterThan(0, $contractId, 'The contract fixture was not inserted.');

        return $contractId;
    }

    /**
     * A provider with a slug the code prefix can be predicted from.
     *
     * providers.slug is NOT NULL and UNIQUE with no default — see the note on
     * ContractDocumentsTest::makeProvider(). One per test is enough here, and
     * each test rolls back, so a fixed slug cannot collide with anything.
     */
    private function makeProvider(): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug' => 'ecrm-test-provider',
            'name' => 'Δοκιμαστικός Πάροχος',
        ]);

        $providerId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $providerId, 'The provider fixture was not inserted.');

        return $providerId;
    }

    /** Read straight from the table: the point is what the write actually did. */
    private function ownerOnDisk(int $contractId): int
    {
        return (int) $this->storedRow(Tables::CONTRACTS, $contractId)['partner_user_id'];
    }

    private function existsOnDisk(int $contractId): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE id = %d',
                Tables::name(Tables::CONTRACTS),
                $contractId
            )
        ) > 0;
    }
}
