<?php

/**
 * (435) Η προμήθεια ακολουθεί τον κάτοχο, και στην εκκρεμή εκκαθάριση.
 *
 * Ανάθεση σε άλλον (μία ή μαζικά) και μεταφορά χαρτοφυλακίου άφηναν την
 * αίτηση στην ΕΚΚΡΕΜΗ παρτίδα του παλιού κατόχου: πληρωνόταν αυτός, και ο νέος
 * δεν τη έβλεπε ποτέ στα ανεκκαθάριστα. Πληρωμένη παρτίδα δεν αγγίζεται.
 *
 * @kanonas K5
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;

final class ReassignReleasesPendingPayoutTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private int $from = 0;

    private int $to = 0;

    private UserScope $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->from      = $this->makeCrmUser(Roles::SELLER);
        $this->to        = $this->makeCrmUser(Roles::SELLER);
        $this->admin     = UserScope::forAdministrator($this->makeCrmUser('administrator'));
    }

    public function testReassignTakesTheContractOutOfThePendingBatch(): void
    {
        $id     = $this->activeContract();
        $payout = $this->putInBatch($id, 'pending');

        self::assertTrue($this->contracts->reassign($id, $this->to, $this->admin));

        self::assertNull($this->storedRow('contracts', $id)['payout_id']);
        // Το σύνολο της παρτίδας ακολουθεί (refreshTotals).
        self::assertSame(0, (int) $this->storedRow('payouts', $payout)['cnt']);
    }

    public function testAPaidBatchIsNotTouched(): void
    {
        $id     = $this->activeContract();
        $payout = $this->putInBatch($id, 'paid');

        self::assertTrue($this->contracts->reassign($id, $this->to, $this->admin));

        self::assertSame($payout, (int) $this->storedRow('contracts', $id)['payout_id']);
    }

    public function testHandOverTakesThePendingOnesToo(): void
    {
        $id = $this->activeContract();
        $this->putInBatch($id, 'pending');

        self::assertSame(1, $this->contracts->handOver($this->from, $this->to, $this->admin));
        self::assertNull($this->storedRow('contracts', $id)['payout_id']);
    }

    private function activeContract(): int
    {
        $id = $this->contracts->create(
            ['status' => 'active', 'supply_number' => '11122233302', 'energy_type' => 'power'],
            UserScope::forSelf($this->from)
        );
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function putInBatch(int $contractId, string $status): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PAYOUTS), [
            'partner_user_id' => $this->from,
            'period'          => '2026-11',
            'cnt'             => 1,
            'amount'          => 50.00,
            'status'          => $status,
        ]);
        $payoutId = (int) $wpdb->insert_id;

        $wpdb->update(
            Tables::name(Tables::CONTRACTS),
            ['payout_id' => $payoutId, 'payout_amount' => 50.00],
            ['id' => $contractId]
        );

        return $payoutId;
    }
}
