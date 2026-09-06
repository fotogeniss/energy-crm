<?php

/**
 * Τι παίρνει μαζί του ο άνθρωπος που φεύγει από την ομάδα.
 *
 * Το «Αφαίρεση» έσβηνε το `ecrm_parent` και σταματούσε εκεί. Επειδή η ορατότητα
 * τρέχει πάνω στο δέντρο, αυτό είχε συνέπειες που κανείς δεν ζήτησε:
 *
 * - **Οι συμβάσεις του έβγαιναν από την εταιρεία μαζί του.** Έμεναν δικές του,
 *   και ο προϊστάμενος που τον αφαίρεσε έπαυε να τις βλέπει. Πελάτες με
 *   ανοιχτές αιτήσεις εξαφανίζονταν από την οθόνη εκείνου που έπρεπε να τους
 *   συνεχίσει.
 * - **Και μαζί τους ολόκληρη η ομάδα του.** Τα παιδιά του κρατούσαν
 *   `ecrm_parent` προς αυτόν· μόλις εκείνος γινόταν ρίζα, το υποδέντρο κοβόταν.
 *   Αφαιρείς έναν, χάνεις πέντε.
 * - **Και τα leads/ανοιχτές εργασίες του, μέχρι 06/09/2026.** Ο έλεγχος
 *   λειτουργίας που έγραψε το DepartingUserTest (εύρημα 5) έλεγξε μόνο την
 *   ΑΛΛΗ διαδρομή προς έξοδο -- Χρήστες → Διαγραφή. Αυτό εδώ, το επίσημο
 *   κουμπί «Αφαίρεση», είχε το ΙΔΙΟ ελάττωμα και κανείς δεν το είχε ελέγξει: ο
 *   προϊστάμενος έπαιρνε συμβάσεις και παιδιά, όχι leads ούτε ανοιχτές
 *   εργασίες -- έμεναν πάνω σε λογαριασμό πλέον χωρίς προϊστάμενο, αόρατα σε
 *   όλους εκτός διαχειριστή.
 *
 * Ο ιδιοκτήτης αποφάσισε 18/08/2026: όλα πάνε στον από πάνω του.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\LeadRepository;
use EnergyCRM\Persistence\TaskRepository;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;
use WP_REST_Response;

final class TeamRemovalTest extends IntegrationTestCase
{
    private TeamRepository $team;

    private ContractRepository $contracts;

    private int $manager;

    private int $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team      = new TeamRepository();
        $this->contracts = new ContractRepository();

        $this->manager = $this->makeCrmUser(Roles::PARTNER);
        $this->member  = $this->makeCrmUser(Roles::SELLER);

        $this->team->attach($this->member, $this->manager);

        wp_set_current_user($this->manager);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Η δουλειά του μένει στην εταιρεία. */
    public function testTheirContractsGoToTheirManager(): void
    {
        $contractId = $this->contractOf($this->member);

        $this->remove();

        self::assertSame(
            $this->manager,
            (int) $this->storedRow('contracts', $contractId)['partner_user_id']
        );
    }

    /** Και η ομάδα του δεν κόβεται από το δέντρο. */
    public function testTheirTeamGoesToTheirManagerToo(): void
    {
        $grandchild = $this->makeCrmUser(Roles::SELLER);
        $this->team->attach($grandchild, $this->member);

        $this->remove();

        self::assertTrue($this->team->reportsDirectlyTo($grandchild, $this->manager));
    }

    /** Και ο ίδιος βγαίνει εκτός — που είναι το νόημα της ενέργειας. */
    public function testTheMemberIsDisabled(): void
    {
        $this->remove();

        self::assertTrue($this->team->isDisabled($this->member));
        self::assertFalse($this->team->reportsDirectlyTo($this->member, $this->manager));
    }

    /** Η απάντηση λέει τι μετακινήθηκε, αντί να το κάνει σιωπηλά. */
    public function testTheAnswerReportsWhatMoved(): void
    {
        $this->contractOf($this->member);
        $this->contractOf($this->member);
        $this->leadOf($this->member);
        $this->taskFor($this->member, 'open');

        $grandchild = $this->makeCrmUser(Roles::SELLER);
        $this->team->attach($grandchild, $this->member);

        $data = $this->remove()->get_data();

        self::assertSame(2, $data['contracts']);
        self::assertSame(1, $data['leads']);
        self::assertSame(1, $data['tasks']);
        self::assertSame(1, $data['members']);
    }

    /**
     * Ίδιο εύρημα με το DepartingUserTest, άλλη διαδρομή προς έξοδο.
     *
     * Χωρίς αυτό, ο υποψήφιος έμενε πάνω σε λογαριασμό πλέον χωρίς
     * προϊστάμενο -- κανείς εκτός διαχειριστή δεν θα τον ξαναέβλεπε, ο πελάτης
     * δεν θα τηλεφωνούσε ποτέ ξανά.
     */
    public function testTheirLeadsGoToTheirManager(): void
    {
        $leadId = $this->leadOf($this->member);

        $this->remove();

        self::assertSame(
            $this->manager,
            (int) $this->storedRow('leads', $leadId)['partner_user_id']
        );
    }

    /** Ίδιο εύρημα, για τις ανοιχτές εργασίες. */
    public function testTheirOpenTasksGoToTheirManager(): void
    {
        $taskId = $this->taskFor($this->member, 'open');

        $this->remove();

        self::assertSame(
            $this->manager,
            (int) $this->storedRow('tasks', $taskId)['assigned_to']
        );
    }

    /**
     * Ολοκληρωμένη εργασία δεν αλλάζει χέρια -- ίδιος κανόνας με το
     * DepartingUserTest: λέει «αυτός ο άνθρωπος έκανε αυτό, τότε».
     */
    public function testACompletedTaskKeepsTheNameOfWhoDidIt(): void
    {
        $taskId = $this->taskFor($this->member, 'done');
        $member = $this->member;

        $this->remove();

        self::assertSame($member, (int) $this->storedRow('tasks', $taskId)['assigned_to']);
    }

    /**
     * Ξένη ομάδα δεν αγγίζεται.
     *
     * Ο έλεγχος `reportsDirectlyTo` υπήρχε ήδη· εδώ επιβεβαιώνεται ότι η
     * μεταφορά δεν του άνοιξε δεύτερη πόρτα.
     */
    public function testAMemberOfAnotherTeamIsNotTouched(): void
    {
        $stranger = $this->makeCrmUser(Roles::SELLER);
        $other    = $this->makeCrmUser(Roles::PARTNER);
        $this->team->attach($stranger, $other);

        $contractId = $this->contractOf($stranger);

        $request = new WP_REST_Request('POST', '/ecrm/v1/team/' . $stranger);
        $request->set_body_params(['id' => $stranger, 'op' => 'remove']);

        self::assertSame(403, rest_do_request($request)->get_status());
        self::assertSame(
            $stranger,
            (int) $this->storedRow('contracts', $contractId)['partner_user_id']
        );
    }

    // --- fixtures ----------------------------------------------------------

    private function remove(): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/team/' . $this->member);
        $request->set_body_params(['id' => $this->member, 'op' => 'remove']);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());

        return $response;
    }

    private function contractOf(int $ownerId): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($ownerId)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }

    private function leadOf(int $ownerId): int
    {
        $leadId = (new LeadRepository())->create(
            ['name' => 'Υποψήφιος Δοκιμής', 'phone' => '2310123456'],
            UserScope::forSelf($ownerId)
        );

        self::assertGreaterThan(0, $leadId, 'Το fixture lead δεν αποθηκεύτηκε.');

        return $leadId;
    }

    private function taskFor(int $ownerId, string $status): int
    {
        $taskId = (new TaskRepository())->create([
            'assigned_to' => $ownerId,
            'created_by'  => $ownerId,
            'title'       => 'Εργασία δοκιμής',
            'status'      => $status,
        ]);

        self::assertGreaterThan(0, $taskId, 'Το fixture εργασίας δεν αποθηκεύτηκε.');

        return $taskId;
    }
}
