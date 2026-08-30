<?php

/**
 * `POST /extract` με `data` + `contract_id` + `apply` — γράψιμο χωρίς νέα ανάγνωση.
 *
 * ## Γιατί υπάρχει αυτή η διαδρομή
 *
 * Η οθόνη επιβεβαίωσης του lead (docs/UI-INTAKE-HANDOFF.html) διαβάζει τα
 * έγγραφα του πελάτη ΠΡΙΝ υπάρξει σύμβαση (`ExtractionFromLeadTest`), για να
 * δείξει στον πωλητή τι βρέθηκε. Μόλις ο πωλητής πατήσει «Ναι, συνέχισε», τα
 * ΙΔΙΑ έγγραφα κρέμονται ήδη στη νέα αίτηση
 * (`ECRM_Files::attach_lead_to_contract()`), και το JSON που ο browser έχει
 * ήδη από την πρώτη ανάγνωση περνάει εδώ ως `data`. Ο `ECRM_Extractor` ΔΕΝ
 * ξανακαλείται -- δεν υπάρχει cache εκεί (ελέγχθηκε: μηδέν αναφορές σε
 * cache/transient/md5/sha1), άρα μια δεύτερη πραγματική κλήση θα πλήρωνε το
 * ίδιο μοντέλο δεύτερη φορά, σε ΚΑΘΕ αίτηση, για πάντα. Δες CHANGELOG (171).
 *
 * ## Τι δοκιμάζεται εδώ
 *
 * Αυτή η διαδρομή δεν αγγίζει ποτέ τον εξαγωγέα -- είναι καθαρή εγγραφή στη
 * βάση μέσω `applyToRecords()`, ήδη δοκιμασμένη έμμεσα από άλλα σημεία. Εδώ
 * δοκιμάζεται το ΝΕΟ κομμάτι: η επιλογή αυτής της διαδρομής, ο έλεγχος
 * εμβέλειας, η απόρριψη `lead_id`/ανεβασμένων αρχείων, και ότι ο browser δεν
 * μπορεί να γράψει σε αυθαίρετη στήλη μέσω άγνωστου κλειδιού στο `data`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class ExtractionApplyOnlyTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/extract';

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

    /** Η βασική διαδρομή: κενά πεδία γεμίζουν από το `data`, χωρίς κλήση εξαγωγέα. */
    public function testDataIsAppliedToEmptyFields(): void
    {
        // Χωρίς ΚΑΝΕΝΑ πεδίο πελάτη το ContractSaveController δεν φτιάχνει καν
        // γραμμή customers (`$customer !== []`, ContractSaveController.php:165)
        // -- οπότε πρέπει να σταλεί τουλάχιστον ένα, με το first_name κενό,
        // ώστε να υπάρχει πελάτης να γραφτεί το first_name πάνω του.
        $contractId = $this->makeContract(['status' => 'draft', 'mobile' => '6900000000']);

        $response = $this->extract([
            'contract_id' => $contractId,
            'apply'       => '1',
            'data'        => wp_json_encode(['first_name' => 'Μαρία', 'supply_number' => '41 50238104']),
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        $data = $response->get_data();
        self::assertTrue($data['ok']);
        self::assertFalse(
            $data['extracted'],
            'Το `extracted:false` λείπει -- ο client δεν θα ξέρει ότι δεν έτρεξε το AI.'
        );
        self::assertContains('first_name', $data['applied']);
        self::assertContains('supply_number', $data['applied']);
    }

    /** Πεδίο που ήδη έχει τιμή δεν αντικαθίσταται -- ίδιος κανόνας με την εφαρμογή από πραγματική εξαγωγή. */
    public function testExistingValuesAreNeverOverwritten(): void
    {
        $contractId = $this->makeContract(['status' => 'draft', 'first_name' => 'Υπάρχον']);

        $response = $this->extract([
            'contract_id' => $contractId,
            'apply'       => '1',
            'data'        => wp_json_encode(['first_name' => 'Νέο Όνομα']),
        ]);

        self::assertSame(200, $response->get_status());
        self::assertNotContains('first_name', $response->get_data()['applied']);

        global $wpdb;
        $customerId = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT customer_id FROM %i WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $contractId
        ));
        $stored = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT first_name FROM %i WHERE id = %d',
            Tables::name(Tables::CUSTOMERS),
            $customerId
        ));

        self::assertSame('Υπάρχον', $stored, 'Το γράψιμο πάτησε τιμή που υπήρχε ήδη.');
    }

    /** Άγνωστο κλειδί στο `data` δεν γράφεται -- ο browser δεν διαλέγει στήλη. */
    public function testUnknownKeyIsIgnored(): void
    {
        $contractId = $this->makeContract(['status' => 'draft']);

        $response = $this->extract([
            'contract_id' => $contractId,
            'apply'       => '1',
            'data'        => wp_json_encode(['role' => 'administrator', 'notes' => 'δεν έπρεπε να γραφτεί εδώ']),
        ]);

        self::assertSame(200, $response->get_status());
        self::assertSame([], $response->get_data()['applied'], 'Άγνωστο/μη επιτρεπτό κλειδί γράφτηκε.');
    }

    // --- ΑΦΜ: AUDIT εύρημα 30/08 -- η μόνη γραφή χωρίς επικύρωση -------------

    /**
     * Το ίδιο κενό που περιέγραψε το εξωτερικό audit: το ΑΦΜ που διάβασε το
     * AI έγραφε κατευθείαν, χωρίς κανέναν έλεγχο ψηφίου -- σε αντίθεση με το
     * ContractSaveController, που απορρίπτει ολόκληρο το save σε μη έγκυρο
     * ΑΦΜ. Ένα λάθος ψηφίο δεν πρέπει να μπλοκάρει ΟΛΟΚΛΗΡΗ την εφαρμογή
     * όμως -- μόνο το ίδιο το πεδίο δεν πρέπει να γραφτεί.
     */
    public function testAnInvalidAfmIsNotAppliedButOtherFieldsStillAre(): void
    {
        // mobile: χωρίς ΚΑΝΕΝΑ πεδίο πελάτη το ContractSaveController δεν
        // φτιάχνει καν γραμμή customers (βλ. testDataIsAppliedToEmptyFields).
        $contractId = $this->makeContract(['status' => 'draft', 'mobile' => '6900000000']);

        $response = $this->extract([
            'contract_id' => $contractId,
            'apply'       => '1',
            'data'        => wp_json_encode(['afm' => '123456789', 'first_name' => 'Μαρία']),
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertNotContains(
            'afm',
            $response->get_data()['applied'],
            'Ένα ΑΦΜ που αποτυγχάνει τον έλεγχο ψηφίου γράφτηκε.'
        );
        self::assertContains('first_name', $response->get_data()['applied'], 'Το ΑΦΜ μπλόκαρε και τα άλλα πεδία.');

        global $wpdb;
        $customerId = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT customer_id FROM %i WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $contractId
        ));
        $stored = $wpdb->get_var($wpdb->prepare(
            'SELECT afm FROM %i WHERE id = %d',
            Tables::name(Tables::CUSTOMERS),
            $customerId
        ));

        self::assertTrue($stored === null || $stored === '', 'Το μη έγκυρο ΑΦΜ έφτασε στη βάση.');
    }

    /** Control: ένα ΑΦΜ που περνάει τον έλεγχο ψηφίου γράφεται κανονικά. */
    public function testAValidAfmIsApplied(): void
    {
        $contractId = $this->makeContract(['status' => 'draft', 'mobile' => '6900000000']);

        $response = $this->extract([
            'contract_id' => $contractId,
            'apply'       => '1',
            'data'        => wp_json_encode(['afm' => '090003373']),
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertContains('afm', $response->get_data()['applied']);
    }

    /** Σύμβαση άλλου partner απαντά όπως και ανύπαρκτη. */
    public function testAContractOfAnotherPartnerIsRefused(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));
        $contractId = $this->makeContract(['status' => 'draft']);

        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $response = $this->extract([
            'contract_id' => $contractId,
            'apply'       => '1',
            'data'        => wp_json_encode(['first_name' => 'Δεν πρέπει να γραφτεί']),
        ]);

        self::assertSame(404, $response->get_status(), 'Διέρρευσε δικαίωμα εγγραφής σε αίτηση άλλου partner.');
    }

    /** `data` μαζί με `lead_id` απορρίπτεται -- η διαδρομή γράφει μόνο σε αίτηση. */
    public function testLeadIdWithDataIsRefused(): void
    {
        $response = $this->extract([
            'lead_id' => 1,
            'apply'   => '1',
            'data'    => wp_json_encode(['first_name' => 'Χ']),
        ]);

        self::assertSame(400, $response->get_status());
    }

    /** `data` χωρίς `apply` απορρίπτεται -- το γράψιμο μένει ρητά opt-in. */
    public function testDataWithoutApplyIsRefused(): void
    {
        $contractId = $this->makeContract(['status' => 'draft']);

        $response = $this->extract([
            'contract_id' => $contractId,
            'data'        => wp_json_encode(['first_name' => 'Χ']),
        ]);

        self::assertSame(400, $response->get_status());
    }

    /** Μη έγκυρο JSON στο `data` απορρίπτεται καθαρά αντί να σκάσει. */
    public function testMalformedDataIsRefused(): void
    {
        $contractId = $this->makeContract(['status' => 'draft']);

        $response = $this->extract([
            'contract_id' => $contractId,
            'apply'       => '1',
            'data'        => '{αυτό δεν είναι JSON',
        ]);

        self::assertSame(400, $response->get_status());
    }

    // --- ο σάκος extra_json (κινητή τηλεφωνία) -------------------------------

    /**
     * Το ICCID καταλήγει ΜΕΣΑ στο `extra_json`, όχι σε στήλη.
     *
     * Η κινητή δεν έχει δικές της στήλες: `sim_number` και `mobile_msisdn`
     * ζουν στον σάκο που γεμίζει το έντυπο του παρόχου. Ο εξαγωγέας
     * επιστρέφει επίπεδα κλειδιά και δεν ξέρει τίποτα από αυτό -- ο
     * διαχωρισμός γίνεται στον controller.
     */
    public function testSimNumberIsWrittenIntoTheExtraBag(): void
    {
        $contractId = $this->makeContract(['status' => 'draft', 'mobile' => '6900000000']);

        $response = $this->extract([
            'contract_id' => $contractId,
            'apply'       => '1',
            'data'        => wp_json_encode(['sim_number' => '8930012345678901234']),
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        // Στη λίστα μπαίνει το ΚΛΕΙΔΙ, όχι το δοχείο: ο πωλητής δεν αναγνωρίζει
        // «extra_json» ως πεδίο, και το «συμπληρώθηκαν N πεδία» πρέπει να
        // μετράει ό,τι βλέπει στην οθόνη.
        self::assertContains('sim_number', $response->get_data()['applied']);
        self::assertNotContains('extra_json', $response->get_data()['applied']);

        self::assertSame('8930012345678901234', $this->extraOf($contractId)['sim_number'] ?? null);
    }

    /**
     * ΤΟ ΚΡΙΣΙΜΟ: ό,τι υπάρχει ήδη στον σάκο επιβιώνει.
     *
     * Δεν υπάρχει «ενημέρωσε ένα κλειδί» σε στήλη JSON -- κάθε γράψιμο
     * αντικαθιστά ολόκληρο το κείμενο. Ένα `sim_number` γραμμένο πάνω σε σάκο
     * που δεν διαβάστηκε πρώτα θα έσβηνε σιωπηλά τις απαντήσεις του εντύπου,
     * χωρίς σφάλμα πουθενά και χωρίς να το δει κανείς μέχρι να τυπωθεί λάθος
     * χαρτί.
     */
    public function testTheRestOfTheExtraBagSurvives(): void
    {
        $contractId = $this->makeContract([
            'status'      => 'draft',
            'mobile'      => '6900000000',
            'energy_type' => 'mobile',
            'extra'       => ['mobile_offer' => 'family', 'subsidy_type' => 'Έκπτωση Παγίου'],
        ]);

        self::assertSame('family', $this->extraOf($contractId)['mobile_offer'] ?? null, 'Το fixture δεν έγραψε extra.');

        $this->extract([
            'contract_id' => $contractId,
            'apply'       => '1',
            'data'        => wp_json_encode(['sim_number' => '8930012345678901234']),
        ]);

        $extra = $this->extraOf($contractId);

        self::assertSame('8930012345678901234', $extra['sim_number'] ?? null);
        self::assertSame('family', $extra['mobile_offer'] ?? null, 'Ο υπόλοιπος σάκος σβήστηκε.');
        self::assertSame('Έκπτωση Παγίου', $extra['subsidy_type'] ?? null, 'Ο υπόλοιπος σάκος σβήστηκε.');
    }

    /** Ό,τι πληκτρολόγησε άνθρωπος μέσα στον σάκο νικά ό,τι διάβασε μοντέλο. */
    public function testAnExistingSimNumberIsNeverOverwritten(): void
    {
        $contractId = $this->makeContract([
            'status'      => 'draft',
            'mobile'      => '6900000000',
            'energy_type' => 'mobile',
            'extra'       => ['sim_number' => '8930099999999999999'],
        ]);

        $response = $this->extract([
            'contract_id' => $contractId,
            'apply'       => '1',
            'data'        => wp_json_encode(['sim_number' => '8930012345678901234']),
        ]);

        self::assertNotContains('sim_number', $response->get_data()['applied']);
        self::assertSame('8930099999999999999', $this->extraOf($contractId)['sim_number'] ?? null);
    }

    // --- fixtures ------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     */
    private function makeContract(array $params): int
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts');
        $request->set_body_params($params);

        $response = rest_do_request($request);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));
        self::assertGreaterThan(0, $data['contract_id'] ?? 0, 'Το fixture δεν αποθηκεύτηκε.');

        return (int) $data['contract_id'];
    }

    /**
     * Ο σάκος `extra_json` της σύμβασης, αποκωδικοποιημένος.
     *
     * @return array<string, mixed>
     */
    private function extraOf(int $contractId): array
    {
        global $wpdb;

        $raw = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT extra_json FROM %i WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $contractId
        ));

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function extract(array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::ROUTE);
        $request->set_body_params($params);

        return rest_do_request($request);
    }
}
