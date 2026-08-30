<?php

/**
 * Η λίστα «Τι χρειάζεται εσένα» του dashboard.
 *
 * Το dashboard έλεγε «7 εκκρεμότητες» και τίποτα άλλο — αριθμός που δεν οδηγεί
 * πουθενά. Η λίστα τον αντικαθιστά, και δύο πράγματα σε αυτήν είναι επιχειρησιακοί
 * κανόνες μέσα σε WHERE και ORDER BY, δηλαδή ακριβώς ό,τι σπάει σιωπηλά:
 *
 *   1. Ποιες καταστάσεις περιμένουν ΤΟΝ ΣΥΝΕΡΓΑΤΗ. Το `routed` λείπει επίτηδες:
 *      εκεί η μπάλα είναι στον πάροχο. Αν κάποιος το προσθέσει «για πληρότητα»,
 *      η λίστα γεμίζει με γραμμές που δεν έχουν ενέργεια και παύει να διαβάζεται.
 *   2. Η σειρά είναι κατά παλαιότητα, όχι κατά κατάσταση. Μια εκκρεμότητα δύο
 *      ημερών δεν είναι πιο επείγουσα από ένα πρόχειρο τριών εβδομάδων.
 *
 * Και ένα τρίτο που δεν είναι κανόνας αλλά ατύχημα που περιμένει: τα ονόματα
 * πελατών είναι κρυπτογραφημένα. Ένα SELECT που ξεχνά το CustomerFields γυρίζει
 * κρυπτοκείμενο, και κρυπτοκείμενο στην οθόνη δεν ρίχνει τίποτα — απλώς φαίνεται.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\DashboardRepository;
use EnergyCRM\Persistence\Tables;

final class DashboardAttentionTest extends IntegrationTestCase
{
    private DashboardRepository $dashboard;

    private ContractRepository $contracts;

    private CustomerRepository $customers;

    private int $alice;

    private int $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboard = new DashboardRepository();
        $this->contracts = new ContractRepository();
        $this->customers = new CustomerRepository();

        $this->alice = $this->makePartner();
        $this->bob   = $this->makePartner();
    }

    public function testItListsOnlyWhatWaitsOnThePartner(): void
    {
        $this->contractFor($this->alice, ['status' => 'pending']);
        $this->contractFor($this->alice, ['status' => 'draft']);
        $this->contractFor($this->alice, ['status' => 'awaiting_signature']);
        $this->contractFor($this->alice, ['status' => 'pending_signature']);
        $this->contractFor($this->alice, ['status' => 'routed']);
        $this->contractFor($this->alice, ['status' => 'active']);

        $out = $this->dashboard->needsAttention($this->alice);

        self::assertCount(4, $out);

        $statuses = array_map(static fn (array $r): string => (string) $r['status'], $out);
        sort($statuses);

        self::assertSame(['awaiting_signature', 'draft', 'pending', 'pending_signature'], $statuses);
    }

    /**
     * `pending_signature` -- ο πάροχος γύρισε πίσω την αίτηση ζητώντας νέα
     * υπογραφή. Δική του assertion, ίδιος λόγος με το `routed` παρακάτω: η
     * αποτυχία πρέπει να λέει ΤΙ έλειψε, όχι απλώς έναν αριθμό που άλλαξε.
     */
    public function testAContractSentBackForANewSignatureIsMyProblem(): void
    {
        $this->contractFor($this->alice, ['status' => 'pending_signature']);

        $out = $this->dashboard->needsAttention($this->alice);

        self::assertCount(1, $out);
        self::assertSame('pending_signature', $out[0]['status']);
    }

    /**
     * Το `routed` είναι το ένα που θα έμπαινε «για πληρότητα». Δική του
     * assertion ώστε η αποτυχία να λέει ΤΙ έσπασε, όχι απλώς «τρία ≠ τέσσερα».
     */
    public function testAContractWaitingOnTheProviderIsNotMyProblem(): void
    {
        $this->contractFor($this->alice, ['status' => 'routed']);

        self::assertSame([], $this->dashboard->needsAttention($this->alice));
    }

    public function testItNeverShowsAnotherPartnersWork(): void
    {
        $this->contractFor($this->bob, ['status' => 'pending']);

        self::assertSame([], $this->dashboard->needsAttention($this->alice));
    }

    /** Το πιο ξεχασμένο πρώτο, ανεξάρτητα από κατάσταση. */
    public function testTheMostForgottenComesFirst(): void
    {
        $fresh = $this->contractFor($this->alice, ['status' => 'pending']);
        $stale = $this->contractFor($this->alice, ['status' => 'draft']);

        $this->ageContract($stale, 30);

        $out = $this->dashboard->needsAttention($this->alice);

        self::assertSame($stale, (int) $out[0]['id'], 'the 30-day draft should outrank a fresh pending');
        self::assertSame($fresh, (int) $out[1]['id']);
        self::assertGreaterThanOrEqual(29, (int) $out[0]['days']);
    }

    public function testItStopsAtTheLimit(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->contractFor($this->alice, ['status' => 'pending']);
        }

        self::assertCount(5, $this->dashboard->needsAttention($this->alice));
        self::assertCount(2, $this->dashboard->needsAttention($this->alice, 2));
    }

    /**
     * Το όνομα φτάνει διαβασμένο, όχι κρυπτογραφημένο.
     *
     * Αυτό είναι το test που δικαιολογεί τα δύο ερωτήματα αντί για ένα join:
     * το CustomerFields::fromStorage περιμένει σχήμα πελάτη, και ανακατεμένες
     * στήλες θα το έσπαγαν σιωπηλά.
     */
    public function testTheCustomerNameArrivesReadable(): void
    {
        $this->encryptionOn();

        $this->contractFor($this->alice, ['status' => 'pending'], $this->customerData());

        $out = $this->dashboard->needsAttention($this->alice);

        self::assertCount(1, $out);
        self::assertSame('Γιώργος Παπαδόπουλος', $out[0]['customer']);
    }

    /**
     * Ένα πρόχειρο χωρίς ΑΦΜ δεν οριστικοποιείται — το λέει ο DraftExitGate —
     * και είναι η μόνη περίπτωση όπου η οθόνη ξέρει τι ακριβώς λείπει.
     */
    public function testADraftWithoutAnAfmIsFlaggedAsBlocked(): void
    {
        $withAfm = $this->customerData();
        $noAfm   = $this->customerData();
        unset($noAfm['afm']);

        $blocked = $this->contractFor($this->alice, ['status' => 'draft'], $noAfm);
        $this->ageContract($blocked, 5);

        $this->contractFor($this->alice, ['status' => 'draft'], $withAfm);

        $out   = $this->dashboard->needsAttention($this->alice);
        $byId  = [];

        foreach ($out as $row) {
            $byId[(int) $row['id']] = $row;
        }

        self::assertTrue((bool) $byId[$blocked]['blocked_no_afm']);
    }

    /** Μια σύμβαση που δεν είναι πρόχειρο δεν σημαδεύεται ποτέ ως μπλοκαρισμένη. */
    public function testOnlyDraftsAreEverFlaggedAsBlocked(): void
    {
        $noAfm = $this->customerData();
        unset($noAfm['afm']);

        $this->contractFor($this->alice, ['status' => 'pending'], $noAfm);

        $out = $this->dashboard->needsAttention($this->alice);

        self::assertFalse((bool) $out[0]['blocked_no_afm']);
    }

    /** Το customer_id δεν διαρρέει στο frontend: δεν το χρειάζεται. */
    public function testTheCustomerIdDoesNotTravelToTheScreen(): void
    {
        $this->contractFor($this->alice, ['status' => 'pending'], $this->customerData());

        self::assertArrayNotHasKey('customer_id', $this->dashboard->needsAttention($this->alice)[0]);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $customer
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

    /** Σπρώχνει το updated_at πίσω, γιατί η σειρά κρίνεται από αυτό. */
    private function ageContract(int $contractId, int $days): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY) WHERE id = %d',
                Tables::name(Tables::CONTRACTS),
                $days,
                $contractId
            )
        );
    }
}
