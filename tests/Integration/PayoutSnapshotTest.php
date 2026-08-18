<?php

/**
 * Η οθόνη προμηθειών δείχνει ό,τι πληρώθηκε, όχι ό,τι θα πληρωνόταν σήμερα.
 *
 * Ο ιδιοκτήτης αποφάσισε στις 18/08/2026 ότι η εκκαθάριση είναι στιγμιότυπο:
 * πληρωμένη παρτίδα δεν αλλάζει αναδρομικά επειδή άλλαξε αργότερα κανόνας
 * προμήθειας. Η βάση το τηρούσε ήδη — η παρτίδα κρατά δικό της `amount` — αλλά
 * η οθόνη υπολόγιζε **πάντα** ζωντανά, ανά σύμβαση. Δηλαδή η απόφαση ζούσε στη
 * μία από τις δύο πλευρές, και ο συνεργάτης έβλεπε νούμερο διαφορετικό από αυτό
 * που εισέπραξε, χωρίς τίποτα να το εξηγεί. Εύρημα 20.7, το μισό του.
 *
 * ## Γιατί τα ποσά εδώ δεν βγαίνουν από κανόνες
 *
 * Το `ECRM_Commissions::active_rules()` κρατά στατική μνήμη ανά αίτημα, και σε
 * σουίτα που ζει σε μία διεργασία αυτό σημαίνει «ανά εκτέλεση». Τεστ που
 * άλλαζε κανόνα και ξαναρωτούσε θα μετρούσε το cache, όχι τη συμπεριφορά.
 *
 * Χωρίς κανόνες ο ζωντανός υπολογισμός δίνει 0.00. Ένα στιγμιότυπο 999.99 πάνω
 * σε σφραγισμένη σύμβαση είναι επομένως αριθμός που **δεν μπορεί** να έχει
 * προκύψει από υπολογισμό: αν εμφανιστεί, διαβάστηκε· αν δεν εμφανιστεί,
 * υπολογίστηκε. Η διάκριση που δοκιμάζεται είναι ακριβώς αυτή.
 *
 * ## Τι δεν καλύπτεται εδώ, και γιατί
 *
 * Η ίδια η δημιουργία παρτίδας (`ECRM_Payouts::create()`), η ακύρωσή της και η
 * βεβαίωση PDF είναι `admin_post` handlers που τελειώνουν σε `wp_safe_redirect`
 * και `exit`. Δεν καλούνται από τη σουίτα χωρίς να σταματήσει η διεργασία.
 * Αυτό που κατοχυρώνεται εδώ είναι η πλευρά της ανάγνωσης — η οθόνη — που
 * είναι και το κομμάτι που έδειχνε λάθος.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;

final class PayoutSnapshotTest extends IntegrationTestCase
{
    /** Ποσό που κανένας υπολογισμός δεν παράγει, με άδειους κανόνες. */
    private const SNAPSHOT = 999.99;

    private ContractRepository $contracts;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->user      = $this->makeCrmUser(Roles::SELLER);

        wp_set_current_user($this->user);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Σφραγισμένη σύμβαση: η οθόνη διαβάζει, δεν υπολογίζει. */
    public function testASettledContractShowsTheAmountItWasSettledWith(): void
    {
        $contractId = $this->payableContract();

        $this->settle($contractId, self::SNAPSHOT, 'paid');

        self::assertSame(self::SNAPSHOT, $this->rowFor()['amount']);
    }

    /** Και τα σύνολα ακολουθούν, γιατί το άθροισμα είναι το νούμερο που κρίνει. */
    public function testTheTotalsFollowTheSnapshot(): void
    {
        $contractId = $this->payableContract();

        $this->settle($contractId, self::SNAPSHOT, 'paid');

        $data = $this->commissions();

        self::assertSame(self::SNAPSHOT, $data['total']);
        self::assertSame(self::SNAPSHOT, $data['paid_total']);
    }

    /**
     * Ασφράγιστη σύμβαση: ζωντανός υπολογισμός, όπως πάντα.
     *
     * Χωρίς αυτό, η πύλη θα μπορούσε να δείχνει το στιγμιότυπο παντού και ο
     * έλεγχος από πάνω θα ήταν ακόμα πράσινος.
     */
    public function testAContractOutsideAnyBatchIsStillCalculatedLive(): void
    {
        $this->payableContract();

        self::assertSame(0.0, $this->rowFor()['amount']);
    }

    /**
     * Σφραγισμένη πριν αρχίσει να κρατιέται στιγμιότυπο.
     *
     * `payout_amount` NULL σε γραμμή που έχει `payout_id`: η οθόνη πέφτει στον
     * ζωντανό υπολογισμό αντί να δείξει μηδέν ή να σκάσει. Είναι η σημερινή
     * συμπεριφορά, και μένει η σωστή για ό,τι δεν έχει στιγμιότυπο.
     */
    public function testASettledContractWithoutASnapshotFallsBackToTheLiveAmount(): void
    {
        $contractId = $this->payableContract();

        $this->settle($contractId, null, 'paid');

        self::assertSame(0.0, $this->rowFor()['amount']);
    }

    /**
     * Οι δύο πηγές στην ίδια απάντηση, και το σύνολο τις ακολουθεί.
     *
     * Δύο συμβάσεις, ίδια κατάσταση, ίδιος συνεργάτης: μόνο η μία έχει μπει σε
     * παρτίδα. Η γραμμή της διαβάζεται, της άλλης υπολογίζεται. Αν η οθόνη
     * διάλεγε μία πηγή για όλα, αυτό εδώ θα κοκκίνιζε ακόμα κι αν όλα τα
     * προηγούμενα ήταν πράσινα.
     */
    public function testTheScreenReadsOneRowAndCalculatesTheOtherInTheSameResponse(): void
    {
        $settled = $this->payableContract();
        $this->payableContract();

        $this->settle($settled, self::SNAPSHOT, 'paid');

        $data    = $this->commissions();
        $amounts = array_column($data['rows'], 'amount');

        sort($amounts);

        self::assertSame([0.0, self::SNAPSHOT], $amounts);
        self::assertSame(self::SNAPSHOT, $data['total']);
    }

    // --- fixtures ----------------------------------------------------------

    /** Σύμβαση σε κατάσταση που κερδίζει προμήθεια. */
    private function payableContract(): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'active', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->user)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }

    /**
     * Βάζει τη σύμβαση σε παρτίδα, με ή χωρίς στιγμιότυπο.
     *
     * Απευθείας στη βάση και όχι μέσω ECRM_Payouts::create(): εκείνη είναι
     * admin_post handler που τελειώνει σε exit. Εδώ στήνεται η κατάσταση που
     * αφήνει πίσω της, που είναι ό,τι διαβάζει η οθόνη.
     */
    private function settle(int $contractId, ?float $amount, string $status): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PAYOUTS), [
            'partner_user_id' => $this->user,
            'period'          => '2026-08',
            'cnt'             => 1,
            'amount'          => $amount ?? 0,
            'status'          => $status,
        ]);

        $payoutId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $payoutId, 'Η παρτίδα δεν αποθηκεύτηκε.');

        $wpdb->update(
            Tables::name(Tables::CONTRACTS),
            ['payout_id' => $payoutId, 'payout_amount' => $amount],
            ['id' => $contractId]
        );
    }

    /**
     * Η γραμμή της οθόνης για μια σύμβαση.
     *
     * @return array<string, mixed>
     */
    private function rowFor(): array
    {
        $rows = $this->commissions()['rows'];

        self::assertCount(
            1,
            $rows,
            'Το test στήνει μία σύμβαση· περισσότερες γραμμές σημαίνουν ότι κάτι άλλο διέρρευσε.'
        );

        return $rows[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function commissions(): array
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/commissions'));

        self::assertSame(200, $response->get_status());

        /** @var array<string, mixed> $data */
        $data = $response->get_data();

        return $data;
    }
}
