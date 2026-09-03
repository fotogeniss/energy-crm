<?php

/**
 * Το /tasks στέλνει ό,τι διαβάζει η οθόνη Εργασίες.
 *
 * Στις 14 Αυγούστου η οθόνη έλεγε «Καμία εργασία» ό,τι κι αν υπήρχε στη βάση.
 * Ο controller απαντούσε `rows`, το `ecrm-view-tasks.js` διάβαζε `d.tasks`, και
 * `undefined || []` είναι κενός πίνακας — χωρίς σφάλμα, χωρίς κόκκινο στο
 * console, με 199 unit και 165 integration tests πράσινα. Το ίδιο και με το
 * `d.team` απέναντι στο `can_team`: ο υπότιτλος κάθε εργασίας έμενε άδειος.
 *
 * Ήταν υπόλοιπο της μετακόμισης των διαδρομών στον TasksController: τρία
 * σημεία του ίδιου αρχείου μετονομάστηκαν σωστά, δύο όχι.
 *
 * ## Γιατί ένα test για ονόματα κλειδιών
 *
 * Επειδή αυτό ακριβώς είναι το κενό. Η PHP πλευρά ήταν σωστή και δοκιμασμένη·
 * η JS πλευρά ήταν συντακτικά έγκυρη. Το σφάλμα ζούσε ΑΝΑΜΕΣΑ τους, όπου δεν
 * κοιτούσε κανένα test — και ένα ασυμφωνία κλειδιού δεν πέφτει, σβήνει.
 *
 * Οπότε αυτό το αρχείο καρφώνει το συμβόλαιο της απάντησης. Δεν μπορεί να
 * διαβάσει JavaScript, άρα δεν εγγυάται ότι η οθόνη ζητάει τα σωστά ονόματα.
 * Εγγυάται ότι τα ονόματα δεν αλλάζουν σιωπηλά: όποιος μετονομάσει το `rows`
 * σπάει εδώ και πάει να δει ποιος το διάβαζε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use WP_REST_Request;

final class TaskListPayloadTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/tasks';

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
     * περνούσε ευχαρίστως δίπλα σε ένα ξεχασμένο δεύτερο κλειδί.
     */
    public function testTheResponseCarriesExactlyTheKeysTheScreenReads(): void
    {
        $data = $this->getTasks();

        self::assertSame(
            ['ok', 'rows', 'can_team'],
            array_keys($data),
            'Το σχήμα της απάντησης άλλαξε. Πριν το αλλάξεις εδώ, ψάξε ποιος το '
            . 'διαβάζει: grep -n "d\\." public/assets/ecrm-view-tasks.js'
        );
    }

    /** Μια εργασία που υπάρχει, φτάνει στην οθόνη. */
    public function testATaskThatExistsComesBackInTheList(): void
    {
        $this->addTask('Επανάκληση πελάτη');

        $data = $this->getTasks();

        self::assertCount(1, $data['rows'], 'Η εργασία δεν επέστρεψε στο rows.');
        self::assertSame('Επανάκληση πελάτη', $data['rows'][0]['title']);
    }

    /**
     * Οι στήλες από τις οποίες χτίζεται ο υπότιτλος.
     *
     * Η οθόνη δεν παίρνει έτοιμο «customer» — το συνθέτει, όπως και η λίστα
     * συμβάσεων. Αυτό το test λέει ότι έχει από τι: αν φύγει το LEFT JOIN, ο
     * υπότιτλος ξαναδειάζει και τίποτα άλλο δεν θα το έλεγε.
     */
    public function testTheJoinedCustomerColumnsAreThereForTheSubtitle(): void
    {
        $customerId = $this->customers->create($this->customerData());
        $contractId = $this->contracts->create(
            ['status' => 'new', 'customer_id' => $customerId],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture της σύμβασης δεν μπήκε.');

        $this->addTask('Υπογραφή', $contractId);

        $row = $this->getTasks()['rows'][0];

        self::assertSame('Γιώργος', $row['first_name']);
        self::assertSame('Παπαδόπουλος', $row['last_name']);
        self::assertArrayHasKey('company_name', $row, 'Χωρίς αυτή, εταιρικός πελάτης δεν έχει όνομα.');
        self::assertSame($this->partner, (int) $row['assigned_to'], 'Χωρίς αυτό δεν βρίσκεται ο ανάδοχος.');
        self::assertArrayHasKey('contract_code', $row, 'Το link της σύμβασης δείχνει τον κωδικό.');
    }

    /**
     * Μια εργασία χωρίς σύμβαση δεν εξαφανίζεται.
     *
     * Το JOIN είναι LEFT ακριβώς γι' αυτό, και ένα INNER θα φαινόταν σωστό σε
     * όποιον το κοιτούσε χωρίς αυτή τη γραμμή.
     */
    public function testATaskWithNoContractStillComesBack(): void
    {
        $this->addTask('Γενική υπενθύμιση');

        $row = $this->getTasks()['rows'][0];

        self::assertSame('Γενική υπενθύμιση', $row['title']);
        self::assertNull($row['contract_id']);
    }

    /**
     * Η εργασία που άργησε το λέει μόνη της.
     *
     * Το `overdue` ΔΕΝ είναι στήλη και δεν ήταν ποτέ στην απάντηση: η λέξη
     * υπήρχε μόνο ως τιμή φίλτρου. Η οθόνη όμως το διάβαζε σε τρία σημεία —
     * κόκκινο pill, η λέξη «εκπρόθεσμη», και η επισήμανση της γραμμής — οπότε
     * και τα τρία ήταν νεκρά από τη μέρα που γράφτηκαν.
     *
     * Δεν το έπιασε η σάρωση κλειδιών της 14ης Αυγούστου, επειδή εκείνη
     * σύγκρινε τα κλειδιά του ΦΑΚΕΛΟΥ της απάντησης (ok/rows/can_team) και όχι
     * τα κλειδιά της ΓΡΑΜΜΗΣ. Το έπιασε το άνοιγμα της οθόνης: εργασία με
     * λήξη 20/06/2026 καθόταν στη λίστα σαν κανονική.
     */
    public function testATaskPastItsDueDateComesBackMarkedOverdue(): void
    {
        $this->addTask('Άργησε', 0, gmdate('Y-m-d H:i:s', strtotime('-3 days')));

        self::assertTrue($this->getTasks()['rows'][0]['overdue']);
    }

    public function testATaskDueLaterIsNotOverdue(): void
    {
        $this->addTask('Αύριο', 0, gmdate('Y-m-d H:i:s', strtotime('+3 days')));

        self::assertFalse($this->getTasks()['rows'][0]['overdue']);
    }

    /** Χωρίς ημερομηνία δεν υπάρχει «άργησε». */
    public function testATaskWithNoDueDateIsNotOverdue(): void
    {
        $this->addTask('Κάποτε');

        self::assertFalse($this->getTasks()['rows'][0]['overdue']);
    }

    /**
     * Πραγματικό bool, όχι το '1'/'0' που θα γύριζε υπολογισμένη στήλη SQL.
     *
     * Αυτό το test φυλάει μια συγκεκριμένη «βελτιστοποίηση»: τη μεταφορά του
     * υπολογισμού μέσα στο SELECT. Το $wpdb θα γύριζε τότε το string '0', και
     * στη JavaScript το '0' είναι **truthy** — κάθε εργασία θα φαινόταν
     * εκπρόθεσμη αντί για καμία. Ίδια οικογένεια σφάλματος, ανάποδο πρόσημο.
     */
    public function testTheOverdueFlagIsABooleanAndNotAString(): void
    {
        $this->addTask('Αύριο', 0, gmdate('Y-m-d H:i:s', strtotime('+3 days')));

        self::assertIsBool($this->getTasks()['rows'][0]['overdue']);
    }

    /**
     * Το άνοιγμα της ΔΙΚΗΣ του λίστας (214) σβήνει το badge για ό,τι μόλις
     * είδε -- ίδιο μπαγκ με το πρώτο screenshot του ιδιοκτήτη 01/09: το
     * badge έμενε στο 2 ενώ οι δύο εργασίες ήταν ήδη ανοιχτές στην οθόνη.
     */
    public function testFetchingTheListMarksOverdueTasksSeen(): void
    {
        $this->addTask('Άργησε', 0, gmdate('Y-m-d H:i:s', strtotime('-3 days')));

        self::assertSame(1, \ECRM_Tasks::due_count($this->partner), 'Πριν το άνοιγμα, το badge πρέπει να δείχνει 1.');

        $this->getTasks();

        self::assertSame(0, \ECRM_Tasks::due_count($this->partner), 'Μετά το άνοιγμα, το badge πρέπει να καθαρίσει.');
    }

    /**
     * Μια εργασία που δεν έχει ξαναφανεί καθόλου -- επειδή δημιουργήθηκε ΜΕΤΑ
     * το τελευταίο άνοιγμα της λίστας -- πρέπει να ξαναανάψει το badge. Το
     * seen_at είναι ανά ΕΡΓΑΣΙΑ, όχι ένα timestamp «τελευταία επίσκεψη».
     */
    public function testANewOverdueTaskReappearsInTheBadgeAfterASecondOneWasSeen(): void
    {
        $this->addTask('Άργησε πρώτη', 0, gmdate('Y-m-d H:i:s', strtotime('-3 days')));
        $this->getTasks();

        self::assertSame(0, \ECRM_Tasks::due_count($this->partner));

        $this->addTask('Άργησε δεύτερη', 0, gmdate('Y-m-d H:i:s', strtotime('-1 day')));

        self::assertSame(1, \ECRM_Tasks::due_count($this->partner), 'Η καινούργια δεν είναι seen ακόμα.');
    }

    /**
     * Το seen_at είναι προσωπικό badge -- η προβολή της ομάδας (`scope=team`)
     * ΔΕΝ πρέπει να καθαρίζει το δικό της badge ενός άλλου χρήστη.
     */
    public function testViewingTeamScopeDoesNotMarkAnyonesTasksSeen(): void
    {
        $owner = $this->makeCrmUser();
        wp_set_current_user($owner);
        $request = new WP_REST_Request('POST', self::ROUTE);
        $request->set_param('title', 'Άργησε άλλου');
        $request->set_param('due_at', gmdate('Y-m-d H:i:s', strtotime('-3 days')));
        $request->set_param('assigned_to', $owner);
        rest_do_request($request);

        wp_set_current_user($this->partner);
        $team = new WP_REST_Request('GET', self::ROUTE);
        $team->set_param('scope', 'team');
        rest_do_request($team);

        self::assertSame(
            1,
            \ECRM_Tasks::due_count($owner),
            'Η προβολή ομάδας από άλλον δεν πρέπει να καθαρίζει το badge του.'
        );
    }

    // --- Fixtures ----------------------------------------------------------

    /** @return array<string, mixed> */
    private function getTasks(): array
    {
        $response = rest_do_request(new WP_REST_Request('GET', self::ROUTE));

        self::assertSame(200, $response->get_status(), 'Το GET /tasks δεν πέρασε τους φύλακες.');

        /** @var array<string, mixed> $data */
        $data = $response->get_data();

        return $data;
    }

    private function addTask(string $title, int $contractId = 0, string $dueAt = ''): void
    {
        $request = new WP_REST_Request('POST', self::ROUTE);
        $request->set_param('title', $title);

        if ($contractId > 0) {
            $request->set_param('contract_id', $contractId);
        }

        if ($dueAt !== '') {
            $request->set_param('due_at', $dueAt);
        }

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status(), 'Το POST /tasks απέτυχε.');
    }
}
