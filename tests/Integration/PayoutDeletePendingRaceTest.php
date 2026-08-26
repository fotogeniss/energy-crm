<?php

/**
 * Race condition: διπλή πληρωμή σε ταυτόχρονη «Πληρώθηκε» + «Διαγραφή».
 *
 * Εύρημα #3 του ελέγχου ασφαλείας/UI-UX/ροής-λογικής (26/08/2026). Ο παλιός
 * `ECRM_Payouts::remove()` διάβαζε το status της παρτίδας με ξεχωριστό
 * SELECT, μετά αποσύνδεε τις συμβάσεις (`payout_id = NULL`) ΧΩΡΙΣ κανέναν
 * όρο, και μόνο στο τέλος δοκίμαζε το guarded DELETE. Ένα ταυτόχρονο
 * `markPaid()` ανάμεσα στο SELECT και το UPDATE των συμβάσεων άφηνε πίσω μια
 * παρτίδα «paid» χωρίς καμία σύμβαση επάνω της -- κι εκείνες, πλέον χωρίς
 * `payout_id`, ξαναγύριζαν στις ανεξόφλητες και μπορούσαν να πληρωθούν ΞΑΝΑ.
 *
 * `PayoutRepository::deletePending()` κλείνει το παράθυρο αντιστρέφοντας τη
 * σειρά: η ΔΙΑΓΡΑΦΗ (guarded 'pending', ατομική πρόταση) πάει πρώτη -- μόνο
 * αν πετύχει αποσυνδέονται οι συμβάσεις. Το τεστ προσομοιώνει το race ρητά,
 * καλώντας `markPaid()` ΑΝΑΜΕΣΑ (χειροκίνητα, όχι με πραγματικά ταυτόχρονα
 * νήματα -- η ίδια τεχνική δοκιμής ατομικότητας που ήδη χρησιμοποιεί το
 * `PayoutPaidAtTest`).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\PayoutRepository;
use EnergyCRM\Persistence\Tables;

final class PayoutDeletePendingRaceTest extends IntegrationTestCase
{
    private PayoutRepository $payouts;

    private ContractRepository $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payouts   = new PayoutRepository();
        $this->contracts = new ContractRepository();
    }

    public function testAPendingBatchIsDeletedAndItsContractsReleased(): void
    {
        $partner    = $this->makePartner();
        $payoutId   = $this->pendingBatch($partner);
        $contractId = $this->contractIn($partner, $payoutId);

        self::assertTrue($this->payouts->deletePending($payoutId));
        self::assertNull($this->payouts->find($payoutId));

        $row = $this->storedRow('contracts', $contractId);
        self::assertNull($row['payout_id']);
        self::assertNull($row['payout_amount']);
    }

    public function testAnAlreadyPaidBatchIsNotDeletedAndKeepsItsContracts(): void
    {
        $partner    = $this->makePartner();
        $payoutId   = $this->pendingBatch($partner);
        $contractId = $this->contractIn($partner, $payoutId);

        self::assertTrue($this->payouts->markPaid($payoutId));

        self::assertFalse($this->payouts->deletePending($payoutId));
        self::assertNotNull($this->payouts->find($payoutId));

        // Η βεβαίωση μιας πληρωμένης παρτίδας πρέπει να συνεχίσει να βρίσκει
        // τη σύμβασή της -- αυτό ακριβώς έσπαγε πριν τη διόρθωση.
        $row = $this->storedRow('contracts', $contractId);
        self::assertSame($payoutId, (int) $row['payout_id']);
    }

    /**
     * ΤΟ ΙΔΙΟ ΤΟ RACE: το `markPaid()` προλαβαίνει ΑΝΑΜΕΣΑ στην απόφαση του
     * χειριστή «Διαγραφή» να προχωρήσει και στην πραγματική εκτέλεση της
     * `deletePending()`. Πριν τη διόρθωση, ο παλιός κώδικας θα είχε ήδη σβήσει
     * το `payout_id` της σύμβασης σε αυτό ακριβώς το σημείο -- εδώ δεν
     * προλαβαίνει καν να ξεκινήσει, γιατί η ίδια η DELETE είναι το πρώτο και
     * μοναδικό σημείο απόφασης.
     */
    public function testMarkPaidWinningTheRaceLeavesTheBatchIntact(): void
    {
        $partner    = $this->makePartner();
        $payoutId   = $this->pendingBatch($partner);
        $contractId = $this->contractIn($partner, $payoutId);

        // Ο διαχειριστής Α ξεκίνησε να διαγράφει· ο διαχειριστής Β προλαβαίνει
        // να πληρώσει πρώτος, ανάμεσα στην απόφαση του Α και την εκτέλεσή της.
        self::assertTrue($this->payouts->markPaid($payoutId));

        // Η ενέργεια του Α τώρα εκτελείται -- και δεν βρίσκει πια τίποτα να σβήσει.
        self::assertFalse($this->payouts->deletePending($payoutId));

        $batch = $this->payouts->find($payoutId);
        self::assertNotNull($batch);
        self::assertSame('paid', $batch['status']);

        $row = $this->storedRow('contracts', $contractId);
        self::assertSame($payoutId, (int) $row['payout_id'], 'Η σύμβαση δεν πρέπει να αποσυνδέθηκε.');
        self::assertNotNull($row['payout_amount'], 'Το στιγμιότυπο ποσού δεν πρέπει να χάθηκε.');
    }

    public function testDeletingAnAbsentBatchIsFalseAndTouchesNoContract(): void
    {
        self::assertFalse($this->payouts->deletePending(999999));
        self::assertFalse($this->payouts->deletePending(0));
    }

    // --- fixtures ------------------------------------------------------

    private function pendingBatch(int $partner): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PAYOUTS), [
            'partner_user_id' => $partner,
            'period'          => '2026-08',
            'cnt'             => 1,
            'amount'          => 50.00,
            'status'          => 'pending',
        ]);

        return (int) $wpdb->insert_id;
    }

    private function contractIn(int $partner, int $payoutId): int
    {
        global $wpdb;

        $id = $this->contracts->create(
            ['status' => 'routed', 'supply_number' => '77766655501', 'energy_type' => 'power'],
            UserScope::forSelf($partner)
        );

        self::assertGreaterThan(0, $id, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        $wpdb->update(
            Tables::name(Tables::CONTRACTS),
            ['payout_id' => $payoutId, 'payout_amount' => 50.00],
            ['id' => $id]
        );

        return $id;
    }
}
