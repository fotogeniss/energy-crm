<?php

/**
 * Το /renewals στέλνει ό,τι διαβάζει η οθόνη «Λήξεις & Ανανεώσεις».
 *
 * Δεύτερο εύρημα της σάρωσης κλειδιών API ↔ JS, στις 2026-08-14, και το ίδιο
 * είδος με το /tasks: ο controller απαντούσε σωστά, η JS ήταν έγκυρη, και το
 * σφάλμα ζούσε ΑΝΑΜΕΣΑ τους. Πέντε αναγνώσεις σε μία οθόνη, καμία εξαίρεση:
 *
 *   d.window  → δεν στέλνεται ποτέ. Ο υπότιτλος τύπωνε κυριολεκτικά
 *               «λήγουν έως undefined ημέρες». Το κλειδί είναι `days`.
 *   d.count   → δεν στέλνεται. Πάντα «0 συμβάσεις», με γεμάτο πίνακα από κάτω.
 *   d.soon    → δεν στέλνεται. Πάντα «0 άμεσα».
 *   r.customer→ δεν στέλνεται. Η στήλη Πελάτης ήταν ΠΑΝΤΑ κενή, με «?» στο
 *               αβαταράκι, γιατί esc(undefined) είναι κενό string.
 *   r.expired → δεν στέλνεται. Ληγμένη σύμβαση έγραφε «Λήγει σε -12η» με το
 *               χρώμα του «σύντομα» αντί για «Έληξε πριν 12η».
 *
 * Και τα πέντε διορθώθηκαν στην JS, όχι εδώ: ο controller στέλνει ήδη ό,τι
 * χρειάζεται η οθόνη — απλώς με άλλα ονόματα. Το `days_left` αρκεί για το
 * `expired`, και οι τρεις στήλες του πελάτη για το όνομα.
 *
 * ## Γιατί ένα test για ονόματα κλειδιών
 *
 * Ίδιος λόγος με το TaskListPayloadTest, και ισχύει ο ίδιος περιορισμός: αυτό
 * το αρχείο δεν μπορεί να διαβάσει JavaScript, άρα δεν εγγυάται ότι η οθόνη
 * ζητάει τα σωστά ονόματα. Εγγυάται ότι τα ονόματα δεν αλλάζουν σιωπηλά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use WP_REST_Request;

final class RenewalsPayloadTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/renewals';

    private ContractRepository $contracts;

    private CustomerRepository $customers;

    private int $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->customers = new CustomerRepository();
        $this->partner   = $this->makeCrmUser();

        wp_set_current_user($this->partner);
    }

    /**
     * Τα τρία κλειδιά της απάντησης, ακριβώς αυτά.
     *
     * assertSame σε ολόκληρο τον πίνακα κλειδιών και όχι assertArrayHasKey ανά
     * ένα: το bug ήταν ΜΕΤΟΝΟΜΑΣΙΑ, και ένας έλεγχος «υπάρχει το rows» θα
     * περνούσε ευχαρίστως δίπλα σε ένα `days` που κάποιος βάφτισε `window`.
     */
    public function testTheResponseCarriesExactlyTheKeysTheScreenReads(): void
    {
        $data = $this->getRenewals();

        self::assertSame(
            ['ok', 'days', 'rows'],
            array_keys($data),
            'Το σχήμα της απάντησης άλλαξε. Πριν το αλλάξεις εδώ, ψάξε ποιος το '
            . 'διαβάζει: grep -n "d\\." public/assets/ecrm-view-renewals.js'
        );
    }

    /**
     * Το παράθυρο ημερών έρχεται ως `days`, και είναι αυτό που ζητήθηκε.
     *
     * Ο υπότιτλος το τυπώνει. Όσο διαβαζόταν ως `window`, η οθόνη έγραφε τη
     * λέξη «undefined» σε κάθε φόρτωση — ορατό σε κάθε χρήστη, αόρατο σε 364
     * πράσινα tests.
     */
    public function testTheWindowComesBackUnderTheKeyTheSubtitlePrints(): void
    {
        $request = new WP_REST_Request('GET', self::ROUTE);
        $request->set_param('days', 45);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());

        /** @var array<string, mixed> $data */
        $data = $response->get_data();

        self::assertSame(45, $data['days']);
    }

    /** Το προεπιλεγμένο παράθυρο, για την πρώτη φόρτωση της οθόνης. */
    public function testTheDefaultWindowIsSixtyDays(): void
    {
        self::assertSame(60, $this->getRenewals()['days']);
    }

    /**
     * Οι στήλες από τις οποίες χτίζεται η γραμμή του πίνακα.
     *
     * Η οθόνη δεν παίρνει έτοιμο «customer» — το συνθέτει, όπως η λίστα
     * συμβάσεων και οι Εργασίες. Αυτό το test λέει ότι έχει από τι: αν φύγει
     * το LEFT JOIN στον πελάτη, η στήλη ξαναδειάζει και τίποτα άλλο δεν θα το
     * έλεγε.
     */
    public function testTheRowCarriesTheColumnsTheTableDraws(): void
    {
        $this->expiringContract(10);

        $row = $this->getRenewals()['rows'][0];

        self::assertSame('Γιώργος', $row['first_name']);
        self::assertSame('Παπαδόπουλος', $row['last_name']);
        self::assertArrayHasKey('company_name', $row, 'Χωρίς αυτή, εταιρικός πελάτης δεν έχει όνομα.');
        self::assertArrayHasKey('code', $row, 'Ο κωδικός της σύμβασης είναι η δεύτερη στήλη.');
        self::assertArrayHasKey('end_date', $row, 'Η ημερομηνία λήξης είναι η τέταρτη στήλη.');
        self::assertArrayHasKey('provider_name', $row, 'Ο πάροχος είναι η τρίτη στήλη.');
        self::assertArrayHasKey('id', $row, 'Χωρίς αυτό το κουμπί «Ανανέωση» δεν ξέρει ποια σύμβαση.');
    }

    /**
     * Το `days_left` είναι αρνητικό όταν η σύμβαση έχει ήδη λήξει.
     *
     * Αυτό είναι ΟΛΟ όσο χρειάζεται η οθόνη για να ξεχωρίσει το «Έληξε» από
     * το «Λήγει σύντομα» — δεν υπάρχει, και δεν χρειάζεται, κλειδί `expired`.
     * Γράφεται ως assertion ώστε όποιος αλλάξει το DATEDIFF σε ABS ή σε
     * GREATEST(0, …) να το μάθει εδώ και όχι από τον πελάτη.
     */
    public function testAnAlreadyExpiredContractComesBackWithNegativeDaysLeft(): void
    {
        $this->expiringContract(-12);

        $row = $this->getRenewals()['rows'][0];

        self::assertLessThan(0, (int) $row['days_left'], 'Χωρίς αρνητικό, η ληγμένη δείχνει ως «λήγει σύντομα».');
    }

    // --- Fixtures ----------------------------------------------------------

    /** @return array<string, mixed> */
    private function getRenewals(): array
    {
        $response = rest_do_request(new WP_REST_Request('GET', self::ROUTE));

        self::assertSame(200, $response->get_status(), 'Το GET /renewals δεν πέρασε τους φύλακες.');

        /** @var array<string, mixed> $data */
        $data = $response->get_data();

        return $data;
    }

    /** A contract whose end_date is $days from today — negative for expired. */
    private function expiringContract(int $days): int
    {
        $customerId = $this->customers->create($this->customerData());

        $contractId = $this->contracts->create(
            [
                'status'      => 'active',
                'customer_id' => $customerId,
                'end_date'    => date('Y-m-d', strtotime($days . ' days')),
            ],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture της σύμβασης δεν μπήκε.');

        return $contractId;
    }
}
