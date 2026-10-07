<?php

/**
 * Η ακύρωση μιας αίτησης που υπήρξε ενεργή (05/10/2026).
 *
 * Ως τις 05/10 μια σύμβαση που υπήρξε ενεργή δεν ακυρωνόταν από καμία πόρτα·
 * τερματιζόταν («Διακοπή»). Ο ιδιοκτήτης ένωσε Διακοπή και ακυρώσεις σε μία
 * «Ακυρώθηκε» και αποφάσισε ότι η προμήθεια μιας αίτησης που δούλεψε μένει.
 * Το αρχείο δοκιμάζει ακριβώς αυτό: η ακύρωση περνάει, και το ιστορικό
 * κρίνει αν κρατιέται η προμήθεια.
 *
 * Το δικαίωμα (`ecrm_exit_active`) για την έξοδο από Ενεργό το φυλάνε οι
 * controllers και έχει δικά του tests· εδώ ο δρόμος είναι ο `ContractLifecycle`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class CancelAfterActiveTest extends IntegrationTestCase
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

        wp_set_current_user($this->user);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Η παλιά Διακοπή: Ενεργός → Ακυρώθηκε περνάει. */
    public function testAnActiveContractCanBeCancelled(): void
    {
        $contractId = $this->activeContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'cancelled'));
        self::assertSame('cancelled', $this->statusOf($contractId));
    }

    /** Και κρατάει την προμήθεια, γιατί το ιστορικό λέει ότι δούλεψε. */
    public function testACancelledContractThatWasActiveKeepsItsCommission(): void
    {
        $contractId = $this->activeContract();

        $this->lifecycle->moveTo($contractId, 'finalisation');
        $this->lifecycle->moveTo($contractId, 'cancelled');

        self::assertTrue(Services::cancellationGate()->keepsCommission($contractId));
    }

    /** Ίδια μετάβαση, αντίθετη απάντηση: εδώ αλλάζει μόνο το ιστορικό. */
    public function testAContractThatWasNeverActiveDoesNotKeepCommission(): void
    {
        $contractId = $this->submittedContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'registration'));
        self::assertTrue($this->lifecycle->moveTo($contractId, 'cancelled'));
        self::assertSame('cancelled', $this->statusOf($contractId));
        self::assertFalse(Services::cancellationGate()->keepsCommission($contractId));
    }

    // --- fixtures ----------------------------------------------------------

    /**
     * Σύμβαση που πέρασε από την ενεργή, με το ιστορικό της γραμμένο.
     *
     * Μέσω moveTo() και όχι με απευθείας UPDATE: το γεγονός που ρωτά η πύλη
     * είναι ακριβώς αυτό που γράφει το moveTo(), και fixture που το παρακάμπτει
     * θα δοκίμαζε μια σύμβαση που η βάση δεν θα είχε δει ποτέ να ενεργοποιείται.
     *
     * Το `finalisation` ΚΑΙ το `active` είναι και τα δύο φυλασσόμενα
     * (`PaperworkGate::gate_statuses()`), άρα τα χαρτιά και η υπογραφή πρέπει
     * να υπάρχουν ΠΡΙΝ φτάσει καν στο `finalisation` -- όχι μόνο πριν το
     * `active` όπως στο παλιό μοντέλο.
     */
    private function activeContract(): int
    {
        $contractId = $this->submittedContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'registration'));
        self::assertTrue($this->lifecycle->moveTo($contractId, 'awaiting_signature'));

        $this->completePaperwork($contractId);

        self::assertTrue($this->lifecycle->moveTo($contractId, 'finalisation'));
        self::assertTrue($this->lifecycle->moveTo($contractId, 'active'));

        return $contractId;
    }

    /**
     * Τα ελάχιστα χαρτιά + υπογραφή που η PaperworkGate απαιτεί πριν από μια
     * φυλασσόμενη κατάσταση. Χωρίς activation_type η απαιτούμενη λίστα είναι
     * η προεπιλογή [id_card, provider_bill] (ECRM_Docs::required_for()).
     */
    private function completePaperwork(int $contractId): void
    {
        $files = Services::files();

        $files->attach($contractId, 'id_card', 'id.jpg', 'image/jpeg', $this->putBytes());
        $files->attach($contractId, 'provider_bill', 'bill.pdf', 'application/pdf', $this->putBytes());

        global $wpdb;

        $wpdb->update(Tables::name('contracts'), ['signed_at' => current_time('mysql')], ['id' => $contractId]);
    }

    private function putBytes(): string
    {
        $saved = ECRM_Files::put_bytes('fixture bytes ' . wp_generate_password(8, false), 'jpg', 'image/jpeg', 'x.jpg');

        self::assertIsArray($saved, 'Fixture failed to write bytes to protected storage.');

        return (string) $saved['path'];
    }

    private function submittedContract(): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'presale', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->user)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }

    private function statusOf(int $contractId): string
    {
        return (string) $this->storedRow('contracts', $contractId)['status'];
    }
}
