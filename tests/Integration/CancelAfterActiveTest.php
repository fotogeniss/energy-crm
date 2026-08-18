<?php

/**
 * Μια σύμβαση που υπήρξε ενεργή δεν ακυρώνεται, από καμία πόρτα.
 *
 * Ο γράφος δεν έχει `cancelled` κάτω από την `active`, και αυτό περνιόταν για
 * κανόνα. Δεν ήταν: η `pending` δέχεται `cancelled` και η `active` πάει στην
 * `pending`, οπότε δύο κλικ έκαναν αυτό που το ένα απαγόρευε. Έλεγχος 18/08,
 * εύρημα 20.6.
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

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\CancellationGate;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Persistence\ContractRepository;
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

    /** Χαρακτηρισμός: το απευθείας Ενεργή → Ακυρώθηκε ήταν ήδη κλειστό. */
    public function testTheDirectMoveWasAlreadyBlocked(): void
    {
        $contractId = $this->activeContract();

        self::assertFalse($this->lifecycle->moveTo($contractId, 'cancelled'));
        self::assertSame('active', $this->statusOf($contractId));
    }

    /** Και τώρα κλείνει και ο δρόμος των δύο βημάτων. */
    public function testTheDetourThroughPendingIsBlockedToo(): void
    {
        $contractId = $this->activeContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'pending'));
        self::assertFalse($this->lifecycle->moveTo($contractId, 'cancelled'));
        self::assertSame('pending', $this->statusOf($contractId));
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
    public function testAContractThatWasNeverActiveIsStillCancelledFromPending(): void
    {
        $contractId = $this->submittedContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'pending'));
        self::assertTrue($this->lifecycle->moveTo($contractId, 'cancelled'));
        self::assertSame('cancelled', $this->statusOf($contractId));
    }

    // --- η πόρτα της οθόνης κατάστασης -------------------------------------

    /** Και με λόγο, όχι με σιωπηλή αποτυχία. */
    public function testTheStatusEndpointAnswersWithTheReason(): void
    {
        $contractId = $this->activeContract();

        $this->lifecycle->moveTo($contractId, 'pending');

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $contractId . '/status');
        $request->set_body_params(['id' => $contractId, 'status' => 'cancelled']);

        $response = rest_do_request($request);

        self::assertSame(409, $response->get_status());
        self::assertSame(CancellationGate::WAS_ACTIVE, $response->get_data()['error']);
        self::assertSame('pending', $this->statusOf($contractId));
    }

    // --- η πόρτα της αποθήκευσης σύμβασης ----------------------------------

    public function testTheSaveEndpointRefusesTheSameMove(): void
    {
        $contractId = $this->activeContract();

        $this->lifecycle->moveTo($contractId, 'pending');

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts');
        $request->set_body_params(['contract_id' => $contractId, 'status' => 'cancelled']);

        $response = rest_do_request($request);

        self::assertSame(409, $response->get_status());
        self::assertSame('pending', $this->statusOf($contractId));
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

        $this->lifecycle->moveTo($wasActive, 'pending');
        $this->lifecycle->moveTo($neverActive, 'pending');

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts/bulk');
        $request->set_body_params([
            'ids'    => [$wasActive, $neverActive],
            'action' => 'status',
            'value'  => 'cancelled',
        ]);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        self::assertSame('pending', $this->statusOf($wasActive));
        self::assertSame('cancelled', $this->statusOf($neverActive));
    }

    // --- fixtures ----------------------------------------------------------

    /**
     * Σύμβαση που πέρασε από την ενεργή, με το ιστορικό της γραμμένο.
     *
     * Μέσω moveTo() και όχι με απευθείας UPDATE: το γεγονός που ρωτά η πύλη
     * είναι ακριβώς αυτό που γράφει το moveTo(), και fixture που το παρακάμπτει
     * θα δοκίμαζε μια σύμβαση που η βάση δεν θα είχε δει ποτέ να ενεργοποιείται.
     */
    private function activeContract(): int
    {
        $contractId = $this->submittedContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'processing'));
        self::assertTrue($this->lifecycle->moveTo($contractId, 'active'));

        return $contractId;
    }

    private function submittedContract(): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
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
