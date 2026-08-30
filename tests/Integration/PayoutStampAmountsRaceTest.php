<?php

/**
 * Race: μια σύμβαση φεύγει από την παρτίδα ΑΝΑΜΕΣΑ στο SELECT και τη γραφή
 * του στιγμιότυπου ποσού.
 *
 * Εσωτερική επισκόπηση 30/08. `ECRM_Payouts::create()` (`admin/class-ecrm-
 * payouts.php`) διαβάζει ΠΟΙΕΣ συμβάσεις δέσμευσε η νέα παρτίδα, και μετά σε
 * ξεχωριστό βρόχο γράφει το `payout_amount` κάθε μίας. Η γραφή ήταν σκέτο
 * `$wpdb->update(..., ['id' => $id])` -- χωρίς `payout_id` στο WHERE. Αν μια
 * ταυτόχρονη `releaseFromPendingBatch()` (π.χ. ακύρωση της σύμβασης) πρόλαβε
 * ανάμεσα στο SELECT και τη γραφή, το `payout_id`/`payout_amount` της ήταν
 * ήδη NULL -- και η αφύλακτη γραφή θα τα ξανάγραφε πάνω σε μια σύμβαση που
 * δεν ανήκει πια σε καμία παρτίδα.
 *
 * Το `ECRM_Payouts::create()` είναι `admin_post` handler που τελειώνει σε
 * `exit` -- η σουίτα δεν μπορεί να τον καλέσει (ίδιος λόγος με το
 * `PayoutSnapshotTest`, `PayoutDeletePendingRaceTest`). Η ίδια η γραφή
 * μεταφέρθηκε σε `PayoutRepository::stampAmounts()`, ακριβώς για να μπορεί
 * να μετρηθεί εδώ.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\PayoutRepository;
use EnergyCRM\Persistence\Tables;

final class PayoutStampAmountsRaceTest extends IntegrationTestCase
{
    private PayoutRepository $payouts;

    private ContractRepository $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payouts   = new PayoutRepository();
        $this->contracts = new ContractRepository();
    }

    public function testStampsTheAmountForAContractStillClaimedByTheBatch(): void
    {
        $partner    = $this->makePartner();
        $payoutId   = $this->pendingBatch($partner);
        $contractId = $this->claimedContract($partner, $payoutId);

        $this->payouts->stampAmounts($payoutId, [$contractId => 42.50]);

        $row = $this->storedRow('contracts', $contractId);
        self::assertSame(42.5, (float) $row['payout_amount']);
        self::assertSame($payoutId, (int) $row['payout_id']);
    }

    /**
     * ΤΟ ΙΔΙΟ ΤΟ RACE: το `releaseFromPendingBatch()` προλαβαίνει ΑΝΑΜΕΣΑ
     * στο SELECT του `ECRM_Payouts::create()` (εδώ: το `claimedContract()`
     * του fixture, που αναπαριστά την κατάσταση αμέσως μετά από αυτό) και
     * τη γραφή του στιγμιότυπου. Πριν τη διόρθωση, η αφύλακτη
     * `$wpdb->update(..., ['id' => $id])` θα ξανάγραφε το ποσό εδώ ακριβώς.
     */
    public function testAContractReleasedBeforeStampingIsNotReStamped(): void
    {
        $partner    = $this->makePartner();
        $payoutId   = $this->pendingBatch($partner);
        $contractId = $this->claimedContract($partner, $payoutId);

        $this->payouts->releaseFromPendingBatch($contractId);

        $this->payouts->stampAmounts($payoutId, [$contractId => 42.50]);

        $row = $this->storedRow('contracts', $contractId);
        self::assertNull($row['payout_id']);
        self::assertNull($row['payout_amount'], 'Η αφύλακτη γραφή θα ξανάγραφε εδώ ένα παλιωμένο ποσό.');
    }

    public function testAnInvalidPayoutIdTouchesNothing(): void
    {
        $partner    = $this->makePartner();
        $contractId = $this->contracts->create(
            ['status' => 'routed', 'supply_number' => '77766655502', 'energy_type' => 'power'],
            UserScope::forSelf($partner)
        );
        self::assertGreaterThan(0, $contractId);

        $this->payouts->stampAmounts(0, [$contractId => 10.0]);

        self::assertNull($this->storedRow('contracts', $contractId)['payout_amount']);
    }

    // --- fixtures ------------------------------------------------------

    private function pendingBatch(int $partner): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PAYOUTS), [
            'partner_user_id' => $partner,
            'period'          => '2026-08',
            'cnt'             => 1,
            'amount'          => 0,
            'status'          => 'pending',
        ]);

        return (int) $wpdb->insert_id;
    }

    /** A contract already claimed by the batch (payout_id set), not yet stamped. */
    private function claimedContract(int $partner, int $payoutId): int
    {
        global $wpdb;

        $id = $this->contracts->create(
            ['status' => 'routed', 'supply_number' => '77766655501', 'energy_type' => 'power'],
            UserScope::forSelf($partner)
        );

        self::assertGreaterThan(0, $id, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        $wpdb->update(Tables::name(Tables::CONTRACTS), ['payout_id' => $payoutId], ['id' => $id]);

        return $id;
    }
}
