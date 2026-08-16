<?php

/**
 * The coverage gap under ContractSaveController's request→column mapping.
 *
 * CHANGELOG 2026-08-16 (2) measured behaviour, not names, and found that
 * ContractSaveValidationTest is the only file that touches save(): five
 * methods, all through one helper that sends the same four constant keys
 * (energy_type, status, first_name, last_name) plus afm and sometimes email.
 * No assertion in the suite ever reads a stored row. contractFrom() writes
 * 32 columns; two of the values that reach it from a request are exercised,
 * and both happen to equal the code's own defaults ('power', draft) — so a
 * renamed column would leave all 199 unit and all 186 integration tests
 * green. Same family as `rows` against `d.tasks`: two sides, each valid on
 * its own, the gap at the border — except both sides here are PHP.
 *
 * The six tests below are that net, in the shape HANDOVER §6β asked for:
 * assertSame against the whole set of columns a save is supposed to touch,
 * not assertArrayHasKey one at a time — an assertArrayHasKey pass does not
 * notice that supply_number landed under meter_number's key.
 *
 * Test 2 found the destructive full-overwrite bug (CHANGELOG 2026-08-16 (3)),
 * and the manual repro on crm-test confirmed it reaches the screen
 * (CHANGELOG 2026-08-16 (4)). CHANGELOG 2026-08-16 (5) fixed it: contractFrom()
 * and addressFrom() now omit a column/block entirely on an edit that didn't
 * send it, and resolveCustomer() keeps the existing customer_id instead of
 * defaulting to 0. Test 2 was rewritten from "pin whichever behaviour is
 * real" into a regression test for that fix, and test 7 was added to pin the
 * address-block half of it. Both would fail again on the old code.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\ContractAddresses;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class ContractSaveMappingTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/contracts';

    /** Passes the check-digit test — never masks a mapping failure as a 422. */
    private const VALID_AFM = '090003373';

    /**
     * Στήλες contracts που ελέγχει το test 2 — ό,τι μπορεί να γράψει η
     * contractFrom() εκτός consent. Μια λίστα, όχι επανάληψη ενσωματωμένη σε
     * δύο σημεία, ώστε το "πριν" και το "μετά" να προβάλλονται με τον ίδιο
     * ακριβώς κατάλογο στηλών.
     *
     * @var list<string>
     */
    private const CONTRACT_TRACKED_COLUMNS = [
        'customer_id', 'provider_id', 'program_id', 'energy_type', 'category',
        'price_type', 'customer_type', 'activation_type', 'supply_number',
        'meter_number', 'invoice_code', 'status', 'notes', 'extracted_json',
        'extra_json', 'start_date', 'term_months', 'end_date',
        'supply_addr_same', 'supply_street', 'supply_street_no', 'supply_city',
        'supply_postal_code', 'supply_region',
        'billing_addr_same', 'billing_street', 'billing_street_no', 'billing_city',
        'billing_postal_code', 'billing_region',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user($this->makeCrmUser(Roles::SELLER));
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * 1. Δημιουργία με κάθε κλειδί συμπληρωμένο, και ανάγνωση της γραμμής πίσω.
     *
     * Every CUSTOMER_FIELDS key, every column contractFrom() can write outside
     * consent, and both addresses filled with DIFFERENT values on purpose —
     * supply_number and meter_number get deliberately unmistakable values,
     * because a swap between them is the exact regression the coverage note
     * warns about, and identical-looking test data would hide it.
     *
     * Expected either way: this is the code the mapping has always run as,
     * so a straight read-back should match what was sent. A red result here
     * means an existing swap or drop, found for the first time.
     */
    public function testCreatingWithEveryFieldFilledWritesEachValueToItsOwnColumn(): void
    {
        $providerId = $this->makeProvider();
        $programId  = $this->makeProgram($providerId);

        $payload = [
            // Customer columns — CUSTOMER_FIELDS, all seventeen.
            'customer_type' => 'company',
            'afm'           => self::VALID_AFM,
            'doy'           => 'Α ΘΕΣΣΑΛΟΝΙΚΗΣ',
            'first_name'    => 'Ελένη',
            'last_name'     => 'Καραγιάννη',
            'father_name'   => 'Νικόλαος',
            'company_name'  => 'Καραγιάννη ΕΠΕ',
            'adt'           => 'ΑΒ998877',
            'birth_date'    => '1980-05-12',
            'region'        => 'Θεσσαλονίκης',
            'city'          => 'Θεσσαλονίκη',
            'street'        => 'Εγνατία',
            'street_no'     => '100',
            'postal_code'   => '54622',
            'phone'         => '2310998877',
            'mobile'        => '6944112233',
            'email'         => 'eleni@example.test',

            // Contract columns that are not addresses.
            'provider_id'      => $providerId,
            'program_id'       => $programId,
            'energy_type'      => 'gas',
            'category'         => 'business',
            'price_type'       => 'fixed',
            'activation_type'  => 'new_connection',
            'supply_number'    => 'SUPPLY-000111',
            'meter_number'     => 'METER-000222',
            'invoice_code'     => 'INV-000333',
            'status'           => 'new',
            'notes'            => 'Σημείωση δοκιμής χαρτογράφησης',
            'extracted_json'   => '{"ocr":"test"}',
            'extra'            => ['agreed_power' => '8', 'guarantee_amount' => '250'],
            'start_date'       => '2026-01-15',
            'term_months'      => 12,

            // Two distinct, non-"same" addresses.
            'supply_addr_same'   => 0,
            'supply_street'      => 'Οδός Προμήθειας',
            'supply_street_no'   => '5',
            'supply_city'        => 'Πάτρα',
            'supply_postal_code' => '26221',
            'supply_region'      => 'Αχαΐας',
            'billing_addr_same'   => 0,
            'billing_street'      => 'Οδός Χρέωσης',
            'billing_street_no'   => '9',
            'billing_city'        => 'Λάρισα',
            'billing_postal_code' => '41221',
            'billing_region'      => 'Λαρίσης',
        ];

        $response = $this->save($payload);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));
        self::assertTrue($data['ok']);
        self::assertGreaterThan(0, $data['contract_id']);
        self::assertGreaterThan(0, $data['customer_id']);

        $customerRow = $this->storedRow('customers', (int) $data['customer_id']);

        $expectedCustomer = [
            'customer_type' => 'company',
            'afm'           => self::VALID_AFM,
            'doy'           => 'Α ΘΕΣΣΑΛΟΝΙΚΗΣ',
            'first_name'    => 'Ελένη',
            'last_name'     => 'Καραγιάννη',
            'father_name'   => 'Νικόλαος',
            'company_name'  => 'Καραγιάννη ΕΠΕ',
            'adt'           => 'ΑΒ998877',
            'birth_date'    => '1980-05-12',
            'region'        => 'Θεσσαλονίκης',
            'city'          => 'Θεσσαλονίκη',
            'street'        => 'Εγνατία',
            'street_no'     => '100',
            'postal_code'   => '54622',
            'phone'         => '2310998877',
            'mobile'        => '6944112233',
            'email'         => 'eleni@example.test',
        ];

        self::assertSame(
            $expectedCustomer,
            $this->columns($customerRow, $expectedCustomer),
            'Ένα ή περισσότερα customer πεδία δεν κατέληξαν στη σωστή στήλη.'
        );

        $contractRow = $this->storedRow('contracts', (int) $data['contract_id']);

        $expectedContract = [
            'customer_id'     => (string) $data['customer_id'],
            'provider_id'     => (string) $providerId,
            'program_id'      => (string) $programId,
            'energy_type'     => 'gas',
            'category'        => 'business',
            'price_type'      => 'fixed',
            'customer_type'   => 'company',
            'activation_type' => 'new_connection',
            'supply_number'   => 'SUPPLY-000111',
            'meter_number'    => 'METER-000222',
            'invoice_code'    => 'INV-000333',
            'status'          => 'new',
            'notes'           => 'Σημείωση δοκιμής χαρτογράφησης',
            'extracted_json'  => '{"ocr":"test"}',
            'start_date'      => '2026-01-15',
            'term_months'     => '12',
            'end_date'        => '2027-01-15',

            'supply_addr_same'   => '0',
            'supply_street'      => 'Οδός Προμήθειας',
            'supply_street_no'   => '5',
            'supply_city'        => 'Πάτρα',
            'supply_postal_code' => '26221',
            'supply_region'      => 'Αχαΐας',

            'billing_addr_same'   => '0',
            'billing_street'      => 'Οδός Χρέωσης',
            'billing_street_no'   => '9',
            'billing_city'        => 'Λάρισα',
            'billing_postal_code' => '41221',
            'billing_region'      => 'Λαρίσης',
        ];

        self::assertSame(
            $expectedContract,
            $this->columns($contractRow, $expectedContract),
            'Ένα ή περισσότερα contract πεδία δεν κατέληξαν στη σωστή στήλη — έλεγξε ιδίως '
            . 'supply_number/meter_number και supply_*/billing_*, το ζευγάρι που προειδοποιεί το CHANGELOG.'
        );

        self::assertSame(
            ['agreed_power' => '8', 'guarantee_amount' => '250'],
            json_decode((string) $contractRow['extra_json'], true),
            'Ο σάκος extra δεν επέζησε ολόκληρος στο extra_json.'
        );
    }

    /**
     * 2. Επεξεργασία: αλλάζει ό,τι στάλθηκε, δεν πειράζει ό,τι δεν στάλθηκε.
     *
     * Αυτό ΗΤΑΝ η υπόθεση που ήλεγχε το test, πριν επιβεβαιωθεί το αντίθετο
     * (CHANGELOG 2026-08-16 (3): πράσινο με την υπόθεση της πλήρους
     * αντικατάστασης, όχι με την τιτλοφράση) και πριν αναπαραχθεί στην οθόνη
     * (2026-08-16 (4)). Η διόρθωση στο 2026-08-16 (5) έκανε το contractFrom()
     * να παραλείπει εντελώς μια στήλη που δεν στάλθηκε σε ένα edit — αντί να
     * τη γράφει σε default/NULL — και το resolveCustomer() να κρατά το
     * υπάρχον customer_id αντί να το μηδενίζει. Αυτό το test είναι τώρα
     * regression test για εκείνη τη διόρθωση, όχι πια χαρακτηρισμός μιας
     * άγνωστης συμπεριφοράς: γράφτηκε ξανά ώστε να κοκκινίσει αν η παλιά
     * καταστροφική συμπεριφορά ξαναεμφανιστεί.
     *
     * Η προηγούμενη γραμμή-προς-γραμμή τεκμηρίωση της ανάλυσης μένει στο
     * CHANGELOG 2026-08-16 (3)/(4)/(5) — δεν επαναλαμβάνεται εδώ.
     */
    public function testEditingWithOnlyOneFieldSentPreservesEverythingElse(): void
    {
        $providerId = $this->makeProvider();
        $programId  = $this->makeProgram($providerId);

        $created = $this->save([
            'customer_type' => 'individual',
            'afm'           => self::VALID_AFM,
            'first_name'    => 'Θοδωρής',
            'last_name'     => 'Μιχαλόπουλος',

            'provider_id'      => $providerId,
            'program_id'       => $programId,
            'energy_type'      => 'gas',
            'category'         => 'business',
            'price_type'       => 'fixed',
            'activation_type'  => 'new_connection',
            'supply_number'    => 'SUPPLY-AAA',
            'meter_number'     => 'METER-BBB',
            // 'new', not 'active': a first save may only mean draft or new
            // since CHANGELOG 2026-08-16 (10). Fixture data, not an
            // expectation — the assertions below compare this row against
            // itself, before and after an edit.
            'status'           => 'new',
            'notes'            => 'Αρχική σημείωση',
            'start_date'       => '2026-01-01',
            'term_months'      => 12,

            'supply_addr_same'   => 0,
            'supply_street'      => 'Πρώτη Οδός',
            'supply_street_no'   => '1',
            'supply_city'        => 'Βόλος',
            'supply_postal_code' => '38221',
            'supply_region'      => 'Μαγνησίας',
        ]);

        $before      = $created->get_data();
        $contractId  = (int) $before['contract_id'];
        $customerId  = (int) $before['customer_id'];

        self::assertSame(200, $created->get_status());

        $rowBefore = $this->storedRow('contracts', $contractId);

        // Η ΜΟΝΗ αλλαγή που στέλνεται: το contract_id για να στοχεύσει την
        // ίδια γραμμή, και ένα πεδίο. ΤΙΠΟΤΑ άλλο — ούτε καν customer_id.
        $edited = $this->save([
            'contract_id' => $contractId,
            'notes'       => 'Ενημερωμένη σημείωση',
        ]);

        self::assertSame(200, $edited->get_status(), (string) ($edited->get_data()['error'] ?? ''));

        $row = $this->storedRow('contracts', $contractId);

        // Το ΜΟΝΟ πεδίο που η αίτηση πράγματι έστειλε.
        self::assertSame('Ενημερωμένη σημείωση', $row['notes'], 'Το πεδίο που στάλθηκε δεν άλλαξε.');

        // Ό,τι δεν στάλθηκε πρέπει να είναι ΑΚΡΙΒΩΣ όπως ήταν πριν το edit —
        // προβολή της γραμμής πριν το edit πάνω στην ίδια λίστα στηλών, με
        // μόνο το notes αλλαγμένο στη νέα τιμή.
        $expectedAfterEdit          = $this->columns($rowBefore, array_flip(self::CONTRACT_TRACKED_COLUMNS));
        $expectedAfterEdit['notes'] = 'Ενημερωμένη σημείωση';

        self::assertSame(
            $expectedAfterEdit,
            $this->columns($row, array_flip(self::CONTRACT_TRACKED_COLUMNS)),
            'Το edit άλλαξε κάτι πέρα από το notes που στάλθηκε — η γραμμή έπρεπε να μείνει '
            . 'ίδια σε όλα τα υπόλοιπα κλειδιά. Αν αυτό κοκκίνισε, η διόρθωση της 2026-08-16 (5) '
            . 'ξαναχάλασε (contractFrom()/addressFrom() ξαναγράφουν κλειδιά που δεν στάλθηκαν).'
        );

        self::assertSame(
            $customerId,
            (int) ($row['customer_id'] ?? 0),
            'Ο πελάτης αποσυνδέθηκε χωρίς να σταλεί customer_id — έπρεπε να παραμείνει όπως ήταν '
            . '(resolveCustomer() πρέπει να διαβάζει το $existing όταν το αίτημα δεν στέλνει customer_id).'
        );
    }

    /**
     * 3. addr_same = 1 και στα δύο προθέματα — τα πέντε μέρη καθαρίζονται.
     *
     * addressFrom() επιστρέφει σκόπιμα ΝΕΑ κενή PostalAddress όταν η σημαία
     * είναι αληθής, χωρίς να διαβάσει καθόλου τα πέντε πεδία του αιτήματος —
     * γι' αυτό στέλνονται εδώ γεμάτα, για να αποδειχθεί ότι αγνοούνται.
     */
    public function testMarkingBothAddressesSameAsHomeClearsTheirFiveParts(): void
    {
        $response = $this->save([
            'afm'        => self::VALID_AFM,
            'first_name' => 'Νίκος',
            'last_name'  => 'Αντωνίου',

            'supply_addr_same'   => 1,
            'supply_street'      => 'Θα έπρεπε να αγνοηθεί',
            'supply_street_no'   => '99',
            'supply_city'        => 'Καβάλα',
            'supply_postal_code' => '65403',
            'supply_region'      => 'Καβάλας',

            'billing_addr_same'   => 1,
            'billing_street'      => 'Θα έπρεπε κι αυτό',
            'billing_street_no'   => '77',
            'billing_city'        => 'Δράμα',
            'billing_postal_code' => '66100',
            'billing_region'      => 'Δράμας',
        ]);

        $data = $response->get_data();
        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));

        $row = $this->storedRow('contracts', (int) $data['contract_id']);

        foreach ([ContractAddresses::SUPPLY_PREFIX, ContractAddresses::BILLING_PREFIX] as $prefix) {
            self::assertSame(1, (int) $row[$prefix . 'addr_same'], "{$prefix}addr_same δεν αποθηκεύτηκε αληθές.");

            foreach (['street', 'street_no', 'city', 'postal_code', 'region'] as $part) {
                self::assertSame(
                    '',
                    $row[$prefix . $part],
                    "{$prefix}{$part} δεν καθαρίστηκε παρότι addr_same=1."
                );
            }
        }
    }

    /**
     * 4. consent = 1 — γράφονται consent_at και consent_ip· αλλιώς κανένα.
     *
     * Δύο συμβάσεις, με και χωρίς consent, για να φανεί η διαφορά — η μία δεν
     * αποδεικνύει τίποτα χωρίς την άλλη δίπλα της.
     */
    public function testConsentWritesTimestampAndIpOnlyWhenGiven(): void
    {
        $previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        try {
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

            $withConsent = $this->save([
                'afm'        => self::VALID_AFM,
                'first_name' => 'Μαρία',
                'last_name'  => 'Σταύρου',
                'consent'    => 1,
            ]);

            $withData = $withConsent->get_data();
            self::assertSame(200, $withConsent->get_status(), (string) ($withData['error'] ?? ''));

            $rowWith = $this->storedRow('contracts', (int) $withData['contract_id']);

            self::assertNotNull($rowWith['consent_at'], 'Με consent=1, το consent_at πρέπει να γραφτεί.');
            self::assertNotSame('', (string) $rowWith['consent_at']);
            self::assertSame('203.0.113.7', $rowWith['consent_ip']);

            $withoutConsent = $this->save([
                'afm'        => self::VALID_AFM,
                'first_name' => 'Μαρία',
                'last_name'  => 'Σταύρου',
            ]);

            $withoutData = $withoutConsent->get_data();
            self::assertSame(200, $withoutConsent->get_status(), (string) ($withoutData['error'] ?? ''));

            $rowWithout = $this->storedRow('contracts', (int) $withoutData['contract_id']);

            self::assertNull($rowWithout['consent_at'], 'Χωρίς consent, το consent_at δεν πρέπει να γραφτεί.');
            self::assertNull($rowWithout['consent_ip'], 'Χωρίς consent, το consent_ip δεν πρέπει να γραφτεί.');
        } finally {
            if ($previousRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $previousRemoteAddr;
            }
        }
    }

    /**
     * 5. Ξένο customer_id — 404 και τίποτα γραμμένο.
     *
     * Ο πελάτης υπάρχει στη βάση αλλά δεν συνδέεται με καμία σύμβαση μέσα στο
     * scope του actor, άρα το isReachable() πρέπει να τον αρνηθεί — πριν
     * γραφτεί οτιδήποτε, γιατί resolveCustomer() τρέχει πριν από κάθε write.
     */
    public function testAForeignCustomerIdIs404AndWritesNothing(): void
    {
        $foreignCustomerId = (new CustomerRepository())->create($this->customerData(self::VALID_AFM));
        self::assertGreaterThan(0, $foreignCustomerId, 'Το fixture του ξένου πελάτη δεν μπήκε.');

        $contractsBefore = $this->rowCount('contracts');
        $customersBefore = $this->rowCount('customers');

        $response = $this->save([
            'customer_id' => $foreignCustomerId,
            'first_name'  => 'Δεν', // Αγνοείται — το customer_id οδηγεί, όχι τα ονόματα.
            'last_name'   => 'Πειράζει',
        ]);

        $data = $response->get_data();

        self::assertSame(404, $response->get_status());
        self::assertFalse($data['ok']);
        self::assertSame('Ο πελάτης δεν βρέθηκε.', $data['error']);

        self::assertSame($contractsBefore, $this->rowCount('contracts'), 'Γράφτηκε σύμβαση παρότι ο πελάτης απορρίφθηκε.');
        self::assertSame($customersBefore, $this->rowCount('customers'), 'Γράφτηκε/άλλαξε πελάτης παρότι απορρίφθηκε ως ξένος.');
    }

    /**
     * 6. Ο σάκος extra ως πίνακας δίπλα σε πεδία διεύθυνσης — ο φύλακας
     *    is_scalar κάνει αυτό που λέει.
     *
     * addressFrom() διαβάζει $params[prefix.part] χωρίς να υποθέσει τον τύπο.
     * Το docblock της το λέει ρητά: «the request also carries the extras bag,
     * which is an array and must never reach sanitize_text_field()». Εδώ
     * στέλνεται ένας πίνακας ΩΣ ΤΙΜΗ ενός πεδίου διεύθυνσης — ό,τι θα συνέβαινε
     * αν ένα ελαττωματικό αίτημα έστελνε κάτι μη αναμενόμενο εκεί — μαζί με
     * την κανονική extras bag δίπλα του.
     */
    public function testANonScalarAddressPartIsDroppedNotStored(): void
    {
        $response = $this->save([
            'afm'        => self::VALID_AFM,
            'first_name' => 'Παύλος',
            'last_name'  => 'Ιωάννου',
            'extra'      => ['agreed_power' => '5'],

            // Μη αναμενόμενος πίνακας αντί για string — ο φύλακας πρέπει να
            // τον μετατρέψει σε '' αντί να καλέσει sanitize_text_field() πάνω
            // σε πίνακα, που θα ήταν TypeError, όχι σιωπηλή αποτυχία.
            'supply_street' => ['nested' => 'δεν πρέπει να αποθηκευτεί'],
        ]);

        $data = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));
        self::assertTrue($data['ok'], 'Ο μη-scalar τύπος έριξε την αποθήκευση αντί να τον αγνοήσει ο φύλακας.');

        $row = $this->storedRow('contracts', (int) $data['contract_id']);

        self::assertSame('', $row['supply_street'], 'Ο μη-scalar τύπος δεν αγνοήθηκε — αποθηκεύτηκε κάτι.');
        self::assertSame(
            ['agreed_power' => '5'],
            json_decode((string) $row['extra_json'], true),
            'Η κανονική extras bag δίπλα του επηρεάστηκε από τον έλεγχο της διεύθυνσης.'
        );
    }

    /**
     * 7. Edit που δεν αγγίζει ένα address block το αφήνει ακριβώς όπως ήταν·
     *    edit που αγγίζει ΕΝΑ κλειδί του block το επανυπολογίζει ολόκληρο.
     *
     * addressFrom() παραλείπει τώρα ένα block εντελώς σε edit που δεν έστειλε
     * κανένα από τα έξι κλειδιά του (CHANGELOG 2026-08-16 (5), η διόρθωση του
     * cross-contamination bug). Αυτό το test καρφώνει και τα δύο μισά μαζί:
     * η παράλειψη διατηρεί, και το άγγιγμα έστω ενός κλειδιού αντικαθιστά
     * ολόκληρο το block ατομικά — η ίδια συμπεριφορά που είχε πάντα όταν το
     * block στελνόταν (test 3), τώρα επιβεβαιωμένη και στο edit path.
     */
    public function testEditingWithoutTouchingAnAddressBlockLeavesItUntouched(): void
    {
        $response = $this->save([
            'afm'        => self::VALID_AFM,
            'first_name' => 'Κατερίνα',
            'last_name'  => 'Παπαδάκη',

            'supply_addr_same'   => 0,
            'supply_street'      => 'Αρχική Οδός',
            'supply_street_no'   => '3',
            'supply_city'        => 'Χανιά',
            'supply_postal_code' => '73100',
            'supply_region'      => 'Χανίων',

            'billing_addr_same'   => 0,
            'billing_street'      => 'Αρχική Χρέωση',
            'billing_street_no'   => '4',
            'billing_city'        => 'Ρέθυμνο',
            'billing_postal_code' => '74100',
            'billing_region'      => 'Ρεθύμνης',
        ]);

        $data       = $response->get_data();
        $contractId = (int) $data['contract_id'];
        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));

        // Το edit στέλνει ΜΟΝΟ ένα κλειδί billing — τίποτα από το supply.
        $edited = $this->save([
            'contract_id'    => $contractId,
            'billing_street' => 'Νέα Οδός Χρέωσης',
        ]);

        self::assertSame(200, $edited->get_status(), (string) ($edited->get_data()['error'] ?? ''));

        $row = $this->storedRow('contracts', $contractId);

        // Το supply block δεν αγγίχτηκε καθόλου — ίδιες τιμές με πριν το edit.
        self::assertSame('0', $row['supply_addr_same'], 'Το supply block άλλαξε παρότι δεν στάλθηκε.');
        self::assertSame('Αρχική Οδός', $row['supply_street']);
        self::assertSame('3', $row['supply_street_no']);
        self::assertSame('Χανιά', $row['supply_city']);
        self::assertSame('73100', $row['supply_postal_code']);
        self::assertSame('Χανίων', $row['supply_region']);

        // Το billing block αγγίχτηκε — επανυπολογίστηκε ΟΛΟΚΛΗΡΟ, όχι μόνο το
        // ένα κλειδί που στάλθηκε: τα άλλα τέσσερα δεν κράτησαν την παλιά
        // τιμή τους, η ίδια «ατομική» συμπεριφορά του block όπως πάντα.
        self::assertSame('0', $row['billing_addr_same']);
        self::assertSame('Νέα Οδός Χρέωσης', $row['billing_street']);
        self::assertSame('', $row['billing_street_no'], 'Το block δεν επανυπολογίστηκε ολόκληρο.');
        self::assertSame('', $row['billing_city']);
        self::assertSame('', $row['billing_postal_code']);
        self::assertSame('', $row['billing_region']);
    }

    // --- Fixtures και βοηθοί -------------------------------------------------

    /**
     * @param array<string, mixed> $params
     */
    private function save(array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::ROUTE);
        $request->set_body_params($params);

        return rest_do_request($request);
    }

    /**
     * Προβάλλει από μια αποθηκευμένη γραμμή ακριβώς τα κλειδιά του $expected,
     * με την ίδια σειρά — ώστε το assertSame() να ελέγχει τιμή ΚΑΙ όνομα
     * στήλης μαζί, χωρίς να αποτύχει από απλή διαφορά σειράς στον πίνακα.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $expected
     *
     * @return array<string, mixed>
     */
    private function columns(array $row, array $expected): array
    {
        $actual = [];

        foreach (array_keys($expected) as $column) {
            $actual[$column] = array_key_exists($column, $row) ? $row[$column] : '__MISSING_COLUMN__';
        }

        return $actual;
    }

    private function rowCount(string $unprefixedTable): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', Tables::name($unprefixedTable))
        );
    }

    /** A provider with a slug the code prefix can be predicted from. */
    private function makeProvider(): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug' => 'ecrm-mapping-test-provider',
            'name' => 'Δοκιμαστικός Πάροχος Χαρτογράφησης',
        ]);

        $providerId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $providerId, 'Το provider fixture δεν μπήκε.');

        return $providerId;
    }

    private function makeProgram(int $providerId): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => $providerId,
            'name'        => 'Πρόγραμμα Δοκιμής Χαρτογράφησης',
            'energy_type' => 'gas',
            'category'    => 'business',
            'price_type'  => 'fixed',
            'active'      => 1,
            'sort_order'  => 0,
        ]);

        $programId = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $programId, 'Το program fixture δεν μπήκε.');

        return $programId;
    }
}
