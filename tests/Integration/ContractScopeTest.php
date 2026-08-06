<?php

/**
 * The architecture's first principle, checked against a real database.
 *
 * "Authorization is structural": no contract query can be written without
 * saying on whose behalf it runs. That claim has been enforced by the type
 * system — every repository method demands a UserScope — but never verified.
 * A type system guarantees the argument is passed, not that the SQL uses it.
 *
 * This is the IDOR class of bug, the one closed in roadmap step 2. Nothing has
 * been stopping it from coming back.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\UnknownColumns;

final class ContractScopeTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private int $alice;

    private int $bob;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->alice     = $this->makePartner();
        $this->bob       = $this->makePartner();

        $this->contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->alice)
        );

        self::assertGreaterThan(0, $this->contractId);
    }

    public function testAPartnerReadsTheirOwnContract(): void
    {
        $row = $this->contracts->find($this->contractId, UserScope::forSelf($this->alice));

        self::assertNotNull($row);
        self::assertSame('12345678901', $row['supply_number']);
    }

    public function testAPartnerCannotReadSomebodyElsesContract(): void
    {
        self::assertNull($this->contracts->find($this->contractId, UserScope::forSelf($this->bob)));
        self::assertFalse($this->contracts->exists($this->contractId, UserScope::forSelf($this->bob)));
    }

    /** Not found and not writable are the same answer, and must stay so. */
    public function testAPartnerCannotWriteSomebodyElsesContract(): void
    {
        $changed = $this->contracts->update(
            $this->contractId,
            UserScope::forSelf($this->bob),
            ['status' => 'cancelled']
        );

        self::assertFalse($changed);

        $row = $this->contracts->find($this->contractId, UserScope::forSelf($this->alice));

        self::assertNotNull($row);
        self::assertSame('new', $row['status'], 'The contract was changed from outside its owner.');
    }

    public function testAManagerReachesTheirDownline(): void
    {
        $manager = $this->makePartner();

        $row = $this->contracts->find(
            $this->contractId,
            UserScope::forTeam($manager, [$this->alice])
        );

        self::assertNotNull($row);
    }

    public function testAManagerDoesNotReachOutsideTheirDownline(): void
    {
        $manager = $this->makePartner();

        self::assertNull($this->contracts->find(
            $this->contractId,
            UserScope::forTeam($manager, [$this->bob])
        ));
    }

    /**
     * The owner comes from the scope, never from the payload.
     *
     * Rejected rather than ignored: a caller who sends `partner_user_id` is
     * either confused or trying something, and quietly saving the row under a
     * different owner would tell them neither.
     */
    public function testOwnershipCannotBeSetByTheCaller(): void
    {
        $this->expectException(UnknownColumns::class);

        $this->contracts->create(
            ['status' => 'new', 'partner_user_id' => $this->alice],
            UserScope::forSelf($this->bob)
        );
    }
}
