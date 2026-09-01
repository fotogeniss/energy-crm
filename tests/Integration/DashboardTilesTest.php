<?php

/**
 * Τα τέσσερα πλακίδια του Πίνακα — CHANGELOG (119), ευθυγράμμιση με
 * `docs/UI-UX-KIT.html` A1.
 *
 * Κάθε πλακίδιο μετράει διαφορετικό ΕΙΔΟΣ πράγματος, και το κάθε ένα έχει
 * ένα σημείο όπου ένα αφελές ερώτημα θα έδινε λάθος αριθμό — αυτά είναι τα
 * σημεία που ελέγχονται εδώ, όχι η προφανής περίπτωση:
 *
 * 1. **«Ανοιχτές» δεν είναι απλώς «όχι active»** — οι τερματικές (ακυρωμένη,
 *    κλεισμένη) επίσης δεν μετράνε, αλλιώς μια ακυρωμένη αίτηση θα φαινόταν
 *    για πάντα «ανοιχτή».
 * 2. **«Λήγουν σήμερα» δεν είναι «ήδη έληξαν».** Μια αίτηση που το παράθυρο
 *    υπογραφής της πέρασε χθες δεν χρειάζεται υπενθύμιση σήμερα — χρειάζεται
 *    άλλη ενέργεια. Το πλακίδιο μετράει μόνο όσες η προθεσμία πέφτει ΜΕΣΑ
 *    στη σημερινή μέρα.
 * 3. **«Κλεισμένες (μήνας)» μετράει το ΓΕΓΟΝΟΣ, όχι τη ΣΤΗΛΗ.** Μια σύμβαση
 *    που έγινε active τον προηγούμενο μήνα δεν πρέπει να μετράει ξανά αυτόν
 *    τον μήνα μόνο επειδή η στήλη status λέει ακόμα «active» — αλλιώς θα
 *    μετρούσε για πάντα, κάθε μήνα.
 * 4. **Η προμήθεια δεν διπλομετριέται** όταν μια σύμβαση πέρασε από active
 *    πάνω από μία φορά μέσα στο ίδιο παράθυρο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\DashboardRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;

final class DashboardTilesTest extends IntegrationTestCase
{
    private DashboardRepository $dashboard;

    private ContractRepository $contracts;

    private int $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dashboard = new DashboardRepository();
        $this->contracts = new ContractRepository();
        // makeCrmUser(), όχι makePartner(): το πλακίδιο «Εργασίες μου»
        // περνάει από το POST /tasks για να φτιάξει τα δεδομένα του (δες
        // addTask() παρακάτω), και η διαδρομή θέλει Guards::crmUser().
        $this->partner   = $this->makeCrmUser();

        wp_set_current_user($this->partner);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    private function contractFor(string $status): int
    {
        $id = $this->contracts->create(
            ['status' => $status, 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $id);

        return $id;
    }

    /** @param array<string, mixed> $columns */
    private function stamp(int $contractId, array $columns): void
    {
        global $wpdb;

        $wpdb->update(Tables::name(Tables::CONTRACTS), $columns, ['id' => $contractId]);
    }

    /**
     * Γερνάει τη ΣΥΜΒΑΣΗ (όχι γεγονός) N ημέρες πίσω, σχετικά με το τώρα της
     * βάσης — για το κυλιόμενο παράθυρο επτά ημερών του `open_this_week`.
     */
    private function createdDaysAgo(int $contractId, int $days): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET created_at = NOW() - INTERVAL %d DAY WHERE id = %d',
                Tables::name(Tables::CONTRACTS),
                $days,
                $contractId
            )
        );
    }

    /**
     * Καταγράφει `sign_sent_sms` N ώρες πριν, ΣΧΕΤΙΚΑ με το τώρα της βάσης —
     * ίδιο μοτίβο με το `SignExpiryTest::sentHoursAgo()`.
     */
    private function sentHoursAgo(int $contractId, int $hours): void
    {
        global $wpdb;

        (new EventRepository())->record($contractId, 0, 'sign_sent_sms', ['message' => 'δοκιμή']);

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET created_at = NOW() - INTERVAL %d HOUR WHERE contract_id = %d ORDER BY id DESC LIMIT 1',
                Tables::name(Tables::EVENTS),
                $hours,
                $contractId
            )
        );
    }

    /**
     * Καταγράφει `to_status = active` N ημέρες πριν, ΣΧΕΤΙΚΑ με το τώρα της
     * βάσης — ίδιο μοτίβο με το `DashboardCardsTest::ageByDays()`.
     */
    private function becameActiveDaysAgo(int $contractId, int $days): void
    {
        global $wpdb;

        (new EventRepository())->record($contractId, 0, 'status_change', ['to_status' => 'active']);

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET created_at = NOW() - INTERVAL %d DAY WHERE contract_id = %d ORDER BY id DESC LIMIT 1',
                Tables::name(Tables::EVENTS),
                $days,
                $contractId
            )
        );
    }

    private function monthStart(): string
    {
        global $wpdb;

        // Η αρχή του τρέχοντος μήνα, ΣΧΕΤΙΚΑ με το τώρα της βάσης — για τον
        // ίδιο λόγο που τα άλλα βοηθητικά της κλάσης δουλεύουν σχετικά.
        return (string) $wpdb->get_var('SELECT DATE_FORMAT(NOW(), "%Y-%m-01 00:00:00")');
    }

    // ── 1. «Ανοιχτές»: ούτε active, ούτε τερματική ────────────────────

    public function testOpenExcludesActiveAndTerminalStatuses(): void
    {
        $this->contractFor('new');
        $this->contractFor('pending');
        $this->contractFor('active');
        $this->contractFor('cancelled');
        $this->contractFor('terminated');

        $tiles = $this->dashboard->tiles($this->partner, $this->monthStart());

        self::assertSame(2, $tiles['open'], 'Μόνο οι δύο μη-τερματικές, μη-active μετράνε ανοιχτές.');
    }

    /**
     * Ο υπότιτλος «↑ N αυτή την εβδομάδα» πρέπει να είναι ΥΠΟΣΥΝΟΛΟ του
     * νούμερου που επιγράφει — αλλιώς το πλακίδιο αυτοαναιρείται στην οθόνη.
     *
     * Δύο ξεχωριστά πράγματα ελέγχονται μαζί, γιατί μαζί μπορούν να σπάσουν:
     * ότι το παράθυρο των επτά ημερών όντως κόβει (η παλιά δεν μετράει), και
     * ότι το φίλτρο κατάστασης ισχύει ΚΑΙ εδώ (η φρέσκια ακυρωμένη δεν
     * μετράει πουθενά, ούτε στο σύνολο ούτε στην εβδομάδα).
     */
    public function testOpenThisWeekIsASubsetOfOpen(): void
    {
        $this->contractFor('new');

        $old = $this->contractFor('pending');
        $this->createdDaysAgo($old, 10);

        // Φρέσκια αλλά ακυρωμένη: εκτός και από τα δύο νούμερα.
        $this->contractFor('cancelled');

        $tiles = $this->dashboard->tiles($this->partner, $this->monthStart());

        self::assertSame(2, $tiles['open'], 'Η ακυρωμένη δεν είναι ανοιχτή, όσο φρέσκια κι αν είναι.');
        self::assertSame(
            1,
            $tiles['open_this_week'],
            'Μόνο η φρέσκια ΚΑΙ ανοιχτή μετράει στην εβδομάδα.'
        );
        self::assertLessThanOrEqual(
            $tiles['open'],
            $tiles['open_this_week'],
            'Ο υπότιτλος δεν επιτρέπεται ποτέ να ξεπερνά το νούμερο από πάνω του.'
        );
    }

    // ── 2. «Αναμονή υπογραφής»: δύο καταστάσεις μαζί ──────────────────

    public function testAwaitingSignatureCountsBothQualifyingStatuses(): void
    {
        $this->contractFor('pending_signature');
        $this->contractFor('awaiting_signature');
        $this->contractFor('signed');

        $tiles = $this->dashboard->tiles($this->partner, $this->monthStart());

        self::assertSame(2, $tiles['awaiting_signature']);
    }

    // ── 3. «Λήγουν σήμερα»: μέσα στο 48ωρο, όχι ήδη ληγμένο ───────────

    public function testExpiringTodayCountsOnlyWhatIsAboutToExpireWithinToday(): void
    {
        // Το πλακίδιο συγκρίνει με CURDATE()/NOW() της ΙΔΙΑΣ σύνδεσης βάσης
        // (DashboardRepository::countExpiringToday()), όχι της PHP. Ένα
        // σταθερό «42 ώρες πριν» παράγει προθεσμία «τώρα + 6 ώρες», που
        // μπορεί να διασχίσει τα μεσάνυχτα ανάλογα με το πότε τρέχει το
        // τεστ — αυτό ήταν το παλιότερο, αποδεκτό (αλλά περιττό) περιθώριο.
        //
        // Εδώ «παγώνουμε» το ρολόι ΑΥΤΗΣ ΤΗΣ ΣΥΝΔΕΣΗΣ σε μεσημέρι σήμερα με
        // `SET timestamp` — session variable της MySQL/MariaDB που αλλάζει
        // τι επιστρέφουν NOW()/CURDATE()/CURRENT_TIMESTAMP() γι' αυτή τη
        // σύνδεση και μόνο, χωρίς clock mocking στη PHP και χωρίς να
        // αγγίζει το πραγματικό ρολόι του server. Έτσι «τώρα + 6 ώρες» =
        // 18:00 σήμερα πάντα, ανεξάρτητα από το πότε πραγματικά τρέχει το
        // check:all. Μηδενίζεται στο finally, ώστε να μην «διαρρεύσει» στο
        // επόμενο τεστ ακόμα κι αν αυτό αποτύχει.
        global $wpdb;

        $wpdb->query("SET @@session.timestamp = UNIX_TIMESTAMP(CONCAT(CURDATE(), ' 12:00:00'))");

        try {
            // 42 ώρες πριν το παγωμένο «τώρα»: λήγει σε 6 ώρες — 18:00 σήμερα.
            $expiringSoon = $this->contractFor('awaiting_signature');
            $this->sentHoursAgo($expiringSoon, 42);

            // 10 ώρες πριν: λήγει σε 38 ώρες — όχι σήμερα.
            $notYet = $this->contractFor('awaiting_signature');
            $this->sentHoursAgo($notYet, 10);

            // 60 ώρες πριν: το παράθυρο πέρασε ήδη — δεν «λήγει», έχει λήξει.
            $alreadyExpired = $this->contractFor('pending_signature');
            $this->sentHoursAgo($alreadyExpired, 60);

            // Καμία αποστολή καταγεγραμμένη — καμία προθεσμία, δεν μετράει.
            $this->contractFor('awaiting_signature');

            $tiles = $this->dashboard->tiles($this->partner, $this->monthStart());

            self::assertSame(1, $tiles['expiring_today']);
        } finally {
            $wpdb->query('SET @@session.timestamp = DEFAULT');
        }
    }

    // ── 4. «Κλεισμένες (μήνας)»: το γεγονός, όχι η στήλη ──────────────

    public function testClosedMonthCountsTheEventNotJustTheColumn(): void
    {
        // Ίδιο time-boundary flake με το (148)/testExpiringToday...: "2 μέρες
        // πριν" σχετικά με το πραγματικό ρολόι μπορεί να πέσει τον
        // ΠΡΟΗΓΟΥΜΕΝΟ μήνα όταν το check:all τρέχει την 1η ή 2η του μήνα --
        // τότε το closed_month θα μετρούσε 0 αντί για 1, όχι επειδή η
        // λογική είναι λάθος αλλά επειδή το test data σκόνταψε στα όρια του
        // μήνα. Παγώνουμε το «τώρα» της σύνδεσης στη 15η του τρέχοντος
        // μήνα -- ίδιο μοτίβο SET @@session.timestamp, ίδιο finally reset.
        global $wpdb;

        // phpcs: το query είναι ολόκληρο literal, καμία μεταβλητή μέσα του --
        // ίδιο σχήμα με το $wpdb->query("SET @@session.timestamp = ...") της
        // testExpiringTodayCountsOnlyWhatIsAboutToExpireWithinToday() παραπάνω,
        // που περνάει επίσης χωρίς prepare(). Ενδιάμεση μεταβλητή ($freezeAt)
        // έκανε το WordPress.DB.PreparedSQL.NotPrepared να κοκκινίσει, γιατί ο
        // sniff δεν αποδεικνύει στατικά ότι μια μεταβλητή είναι ασφαλής -- το
        // literal concatenation μέσα στην ίδια την κλήση περνάει.
        $wpdb->query(
            "SET @@session.timestamp = UNIX_TIMESTAMP("
            . "CONCAT(DATE_FORMAT(CURDATE(), '%Y-%m-15'), ' 12:00:00'))"
        );

        try {
            $thisMonth = $this->contractFor('active');
            $this->becameActiveDaysAgo($thisMonth, 2);
            $this->stamp($thisMonth, ['payout_amount' => 120]);

            // Έγινε active ΠΕΡΣΙ (ας πούμε 90 μέρες πριν) — η στήλη λέει ακόμα
            // «active» σήμερα, αλλά το γεγονός είναι έξω από το παράθυρο.
            $longAgo = $this->contractFor('active');
            $this->becameActiveDaysAgo($longAgo, 90);
            $this->stamp($longAgo, ['payout_amount' => 80]);

            $tiles = $this->dashboard->tiles($this->partner, $this->monthStart());

            self::assertSame(1, $tiles['closed_month']);
            self::assertEqualsWithDelta(120.0, $tiles['closed_month_commission'], 0.01);
        } finally {
            $wpdb->query('SET @@session.timestamp = DEFAULT');
        }
    }

    public function testClosedMonthExcludesAContractThatWasCancelledAfter(): void
    {
        $wonThenCancelled = $this->contractFor('cancelled');
        $this->becameActiveDaysAgo($wonThenCancelled, 1);
        $this->stamp($wonThenCancelled, ['payout_amount' => 200]);

        $tiles = $this->dashboard->tiles($this->partner, $this->monthStart());

        self::assertSame(
            0,
            $tiles['closed_month'],
            'Η σημερινή στήλη status δεν είναι active πια — δεν πρέπει να μετράει σαν κλεισμένη με προμήθεια.'
        );
        self::assertSame(0.0, $tiles['closed_month_commission']);
    }

    public function testClosedMonthCommissionIsNotDoubleCountedOnReactivation(): void
    {
        // Ίδιος λόγος παγώματος με το test παραπάνω: "10 μέρες πριν" περνάει
        // σε προηγούμενο μήνα όταν τρέχει στην αρχή του μήνα.
        global $wpdb;

        // phpcs: το query είναι ολόκληρο literal, καμία μεταβλητή μέσα του --
        // ίδιο σχήμα με το $wpdb->query("SET @@session.timestamp = ...") της
        // testExpiringTodayCountsOnlyWhatIsAboutToExpireWithinToday() παραπάνω,
        // που περνάει επίσης χωρίς prepare(). Ενδιάμεση μεταβλητή ($freezeAt)
        // έκανε το WordPress.DB.PreparedSQL.NotPrepared να κοκκινίσει, γιατί ο
        // sniff δεν αποδεικνύει στατικά ότι μια μεταβλητή είναι ασφαλής -- το
        // literal concatenation μέσα στην ίδια την κλήση περνάει.
        $wpdb->query(
            "SET @@session.timestamp = UNIX_TIMESTAMP("
            . "CONCAT(DATE_FORMAT(CURDATE(), '%Y-%m-15'), ' 12:00:00'))"
        );

        try {
            $wentActiveTwice = $this->contractFor('active');
            $this->becameActiveDaysAgo($wentActiveTwice, 10);
            $this->becameActiveDaysAgo($wentActiveTwice, 3);
            $this->stamp($wentActiveTwice, ['payout_amount' => 150]);

            $tiles = $this->dashboard->tiles($this->partner, $this->monthStart());

            self::assertSame(1, $tiles['closed_month'], 'Μία σύμβαση, όχι δύο γραμμές.');
            self::assertEqualsWithDelta(150.0, $tiles['closed_month_commission'], 0.01);
        } finally {
            $wpdb->query('SET @@session.timestamp = DEFAULT');
        }
    }

    // ── 5. «Εργασίες μου» ──────────────────────────────────────────────

    public function testTasksCountsOpenAndOverdueSeparately(): void
    {
        $this->addTask('Ανοιχτή, όχι εκπρόθεσμη', gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS));
        $this->addTask('Εκπρόθεσμη', gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS));
        $doneId = $this->addTask('Ολοκληρωμένη — δεν μετράει καθόλου', gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS));

        $mark = new WP_REST_Request('POST', '/ecrm/v1/tasks/' . $doneId);
        $mark->set_param('status', 'done');
        self::assertSame(200, rest_do_request($mark)->get_status());

        $tiles = $this->dashboard->tiles($this->partner, $this->monthStart());

        self::assertSame(2, $tiles['tasks_open'], 'Οι δύο ανοιχτές — η ολοκληρωμένη δεν μετράει.');
        self::assertSame(1, $tiles['tasks_overdue']);
    }

    /**
     * Ίδιο μοτίβο με `TaskListPayloadTest::addTask()` — περνάει από το ίδιο
     * το POST /tasks, ώστε το `assigned_to` να γεμίσει όπως θα γέμιζε στην
     * πράξη (ο controller αναθέτει στον εαυτό του όταν δεν δοθεί άλλος).
     */
    private function addTask(string $title, string $dueAt = ''): int
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/tasks');
        $request->set_param('title', $title);

        if ($dueAt !== '') {
            $request->set_param('due_at', $dueAt);
        }

        $response = rest_do_request($request);
        self::assertSame(200, $response->get_status(), 'Το POST /tasks απέτυχε.');

        return (int) $response->get_data()['id'];
    }
}
