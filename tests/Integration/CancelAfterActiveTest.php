<?php

/**
 * Μια σύμβαση που υπήρξε ενεργή δεν ακυρώνεται, από καμία πόρτα.
 *
 * Ο γράφος δεν έχει καμία ακύρωση κάτω από την `active` -- η `active` πάει
 * μόνο σε `terminated` ή πίσω σε `finalisation` (δες `ContractStatus::
 * allowedNext()`). Το `finalisation` όμως δέχεται και τις δύο ακυρώσεις, άρα
 * το ίδιο δίκλικο σχήμα παραμένει: Active → Finalisation → Ακύρωση θα
 * επέτρεπε αυτό που η απευθείας μετάβαση απαγορεύει.
 *
 * 07/09/2026: το αρχείο μεταφέρθηκε στο νέο λεξιλόγιο -- ο παλιός δρόμος
 * ήταν `new → processing → active`, με `pending` ως τη δίοδο της παράκαμψης·
 * ο νέος είναι `presale → registration → awaiting_signature → finalisation →
 * active`, με `finalisation` ως τη δίοδο. Ίδιο σχήμα σφάλματος, διαφορετικό
 * σταθμό.
 *
 * Τέσσερις διαδρομές γράφουν κατάσταση — η οθόνη κατάστασης, η αποθήκευση
 * σύμβασης, η μαζική ενέργεια και ο ίδιος ο `ContractLifecycle` για cron και
 * εισαγωγή — και το αρχείο τις δοκιμάζει και τις τέσσερις. Κανόνας που τον
 * τηρούν τρεις στις τέσσερις πόρτες δεν είναι κανόνας· είναι το σχήμα λάθους
 * που αυτό το CRM έχει ήδη πληρώσει οκτώ φορές.
 *
 * Η αντίθετη περίπτωση είναι εξίσου σημαντική και είναι εδώ: σύμβαση που δεν
 * υπήρξε ποτέ ενεργή ακυρώνεται κανονικά από την εκκρεμότητα. Χωρίς αυτήν, η
 * πύλη θα μπορούσε να μπλοκάρει τα πάντα και όλα τα υπόλοιπα tests θα ήταν
 * ακόμα πράσινα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\CancellationGate;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;
use WP_REST_Request;

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

    // --- η πόρτα του ContractLifecycle (cron, εισαγωγή) ---------------------

    /** Χαρακτηρισμός: το απευθείας Ενεργή → Ακυρώθηκε ήταν ήδη κλειστό -- ο γράφος δεν το έχει καν. */
    public function testTheDirectMoveWasAlreadyBlocked(): void
    {
        $contractId = $this->activeContract();

        self::assertFalse($this->lifecycle->moveTo($contractId, 'cancelled_by_us'));
        self::assertSame('active', $this->statusOf($contractId));
    }

    /** Και τώρα κλείνει και ο δρόμος των δύο βημάτων, μέσω finalisation. */
    public function testTheDetourThroughFinalisationIsBlockedToo(): void
    {
        $contractId = $this->activeContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'finalisation'));
        self::assertFalse($this->lifecycle->moveTo($contractId, 'cancelled_by_us'));
        self::assertSame('finalisation', $this->statusOf($contractId));
    }

    /** Ο σωστός δρόμος μένει ανοιχτός — αλλιώς δεν θα υπήρχε τρόπος να κλείσει. */
    public function testTerminatingAnActiveContractStillWorks(): void
    {
        $contractId = $this->activeContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'terminated'));
        self::assertSame('terminated', $this->statusOf($contractId));
    }

    /**
     * Σύμβαση που δεν υπήρξε ποτέ ενεργή ακυρώνεται κανονικά.
     *
     * Ίδια μετάβαση, ίδια κατάσταση αφετηρίας, αντίθετη απάντηση: αυτό που
     * αλλάζει είναι μόνο το ιστορικό.
     */
    public function testAContractThatWasNeverActiveIsStillCancelledFromRegistration(): void
    {
        $contractId = $this->submittedContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'registration'));
        self::assertTrue($this->lifecycle->moveTo($contractId, 'cancelled_by_us'));
        self::assertSame('cancelled_by_us', $this->statusOf($contractId));
    }

    // --- η πόρτα της οθόνης κατάστασης -------------------------------------

    /** Και με λόγο, όχι με σιωπηλή αποτυχία. */
    public function testTheStatusEndpointAnswersWithTheReason(): void
    {
        $contractId = $this->activeContract();

        $this->lifecycle->moveTo($contractId, 'finalisation');

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $contractId . '/status');
        $request->set_body_params(['id' => $contractId, 'status' => 'cancelled_by_us']);

        $response = rest_do_request($request);

        self::assertSame(409, $response->get_status());
        self::assertSame(CancellationGate::WAS_ACTIVE, $response->get_data()['error']);
        self::assertSame('finalisation', $this->statusOf($contractId));
    }

    // --- η πόρτα της αποθήκευσης σύμβασης ----------------------------------

    public function testTheSaveEndpointRefusesTheSameMove(): void
    {
        $contractId = $this->activeContract();

        $this->lifecycle->moveTo($contractId, 'finalisation');

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts');
        $request->set_body_params(['contract_id' => $contractId, 'status' => 'cancelled_by_us']);

        $response = rest_do_request($request);

        self::assertSame(409, $response->get_status());
        self::assertSame('finalisation', $this->statusOf($contractId));
    }

    // --- η πόρτα της μαζικής ενέργειας -------------------------------------

    /**
     * Η μαζική ακύρωση αφήνει πίσω τη σύμβαση που υπήρξε ενεργή.
     *
     * Δύο γραμμές στην ίδια παρτίδα, και στην ίδια κατάσταση: η μία ακυρώνεται,
     * η άλλη όχι. Αν η πύλη δεν έφτανε ως εδώ, η μαζική ενέργεια θα ήταν ο
     * εύκολος τρόπος να παρακαμφθεί ό,τι απαγορεύουν οι δύο οθόνες.
     */
    public function testTheBulkActionCancelsOnlyTheContractThatWasNeverActive(): void
    {
        $wasActive   = $this->activeContract();
        $neverActive = $this->submittedContract();

        $this->lifecycle->moveTo($wasActive, 'finalisation');
        $this->lifecycle->moveTo($neverActive, 'registration');

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts/bulk');
        $request->set_body_params([
            'ids'    => [$wasActive, $neverActive],
            'action' => 'status',
            'value'  => 'cancelled_by_us',
        ]);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        self::assertSame('finalisation', $this->statusOf($wasActive));
        self::assertSame('cancelled_by_us', $this->statusOf($neverActive));
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
