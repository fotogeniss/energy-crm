<?php

/**
 * Προμήθεια που ήδη πληρώθηκε δεν εξαφανίζεται με μια ακύρωση.
 *
 * Εύρημα #2 του ελέγχου ασφαλείας/UI-UX/ροής-λογικής (26/08/2026): το
 * `CancellationGate` μπλοκάρει την ακύρωση μόνο όταν η σύμβαση υπήρξε ποτέ
 * `Active`. Όμως το `ContractStatus::isPayable()` περιλαμβάνει και `Routed`
 * και `Resolved` -- μια σύμβαση μπαίνει σε παρτίδα εκκαθάρισης και πληρώνεται
 * ΠΡΙΝ γίνει ποτέ Ενεργή (ο πάροχος την επεξεργάζεται ακόμα). Χωρίς αυτή τη
 * διόρθωση, μια τέτοια σύμβαση μπορούσε να ακυρωθεί κανονικά ΜΕΤΑ την
 * πληρωμή -- ο συνεργάτης κρατούσε την προμήθεια, χωρίς κανένα ίχνος.
 *
 * Ο ιδιοκτήτης επιβεβαίωσε ρητά (AskUserQuestion, 26/08) δύο ξεχωριστές
 * αποφάσεις που αυτό το αρχείο δοκιμάζει και τις δύο:
 *
 *   1. Παρτίδα ήδη ΠΛΗΡΩΜΕΝΗ -> η ακύρωση μπλοκάρεται, ίδια αντιμετώπιση με
 *      το «υπήρξε Ενεργή» (`CancellationGate::WAS_PAID`).
 *   2. Παρτίδα ακόμα ΕΚΚΡΕΜΗΣ (μη πληρωμένη) -> η ακύρωση προχωράει
 *      κανονικά, ΚΑΙ η σύμβαση βγαίνει αυτόματα από την παρτίδα
 *      (`payout_id`/`payout_amount` καθαρίζουν), ώστε το σύνολο που θα
 *      πληρωθεί να μην την περιλαμβάνει πια.
 *
 * Δοκιμάζεται μέσω `ContractLifecycle::moveTo()` -- το ίδιο σημείο περνούν
 * και οι τέσσερις πόρτες (οθόνη κατάστασης, αποθήκευση, μαζική ενέργεια,
 * cron/εισαγωγή), ίδιο σχήμα με το `CancelAfterActiveTest`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\CancellationGate;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class PayoutClawbackGateTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private ContractLifecycle $lifecycle;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->lifecycle = Services::lifecycle();

        $this->user = $this->makeCrmUser(Roles::SELLER);
    }

    /** Payable χωρίς ποτέ να έγινε Ενεργή -- ακριβώς το κενό του ευρήματος. */
    public function testARoutedContractInAPaidBatchCannotBeCancelled(): void
    {
        $contractId = $this->routedContract();
        $this->putInBatch($contractId, 'paid');

        self::assertFalse($this->lifecycle->moveTo($contractId, 'cancelled'));
        self::assertSame('routed', $this->statusOf($contractId));
    }

    /** Ίδιο μήνυμα άρνησης με αυτό που ελέγχει το REST endpoint. */
    public function testTheRefusalReasonIsWasPaid(): void
    {
        $contractId = $this->routedContract();
        $this->putInBatch($contractId, 'paid');

        $reason = (new CancellationGate(Services::events(), Services::payouts()))
            ->refusalOnMove(ContractStatus::Routed, ContractStatus::Cancelled, $contractId);

        self::assertSame(CancellationGate::WAS_PAID, $reason);
    }

    /** Resolved είναι το ίδιο payable με Routed -- δεν είναι ειδική περίπτωση μόνο του Routed. */
    public function testAResolvedContractInAPaidBatchCannotBeCancelled(): void
    {
        $contractId = $this->contracts->create(
            ['status' => 'resolved', 'supply_number' => '99988877701', 'energy_type' => 'power'],
            UserScope::forSelf($this->user)
        );
        $this->putInBatch($contractId, 'paid');

        self::assertFalse($this->lifecycle->moveTo($contractId, 'cancelled'));
        self::assertSame('resolved', $this->statusOf($contractId));
    }

    /** Η αντίθετη περίπτωση: όσο η παρτίδα δεν έχει πληρωθεί, η ακύρωση μένει δυνατή. */
    public function testARoutedContractInAPendingBatchCanStillBeCancelled(): void
    {
        $contractId = $this->routedContract();
        $this->putInBatch($contractId, 'pending');

        self::assertTrue($this->lifecycle->moveTo($contractId, 'cancelled'));
        self::assertSame('cancelled', $this->statusOf($contractId));
    }

    /**
     * Και βγαίνει από την παρτίδα -- όχι μόνο επιτρέπεται η ακύρωση, το
     * σύνολο της εκκρεμούς παρτίδας δεν πρέπει να συνεχίσει να την
     * περιλαμβάνει.
     */
    public function testCancellingDropsTheContractOutOfItsPendingBatch(): void
    {
        $contractId = $this->routedContract();
        $this->putInBatch($contractId, 'pending');

        $this->lifecycle->moveTo($contractId, 'cancelled');

        $row = $this->storedRow('contracts', $contractId);
        self::assertNull($row['payout_id']);
        self::assertNull($row['payout_amount']);
    }

    /** Σύμβαση που δεν μπήκε ποτέ σε καμία παρτίδα ακυρώνεται κανονικά, όπως πάντα. */
    public function testAContractNeverInAnyBatchIsUnaffected(): void
    {
        $contractId = $this->routedContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'cancelled'));
        self::assertSame('cancelled', $this->statusOf($contractId));
    }

    // --- fixtures ------------------------------------------------------

    private function routedContract(): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'routed', 'supply_number' => '11122233301', 'energy_type' => 'power'],
            UserScope::forSelf($this->user)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }

    private function putInBatch(int $contractId, string $status): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PAYOUTS), [
            'partner_user_id' => $this->user,
            'period'          => '2026-08',
            'cnt'             => 1,
            'amount'          => 50.00,
            'status'          => $status,
        ]);

        $payoutId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $payoutId, 'Το fixture παρτίδας δεν αποθηκεύτηκε.');

        $wpdb->update(
            Tables::name(Tables::CONTRACTS),
            ['payout_id' => $payoutId, 'payout_amount' => 50.00],
            ['id' => $contractId]
        );
    }

    private function statusOf(int $contractId): string
    {
        return (string) $this->storedRow('contracts', $contractId)['status'];
    }
}
