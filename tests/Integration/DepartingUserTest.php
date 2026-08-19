<?php

/**
 * Η διαγραφή χρήστη από το wp-admin δεν αφήνει πίσω της ορφανά.
 *
 * Το CRM έχει δικό του «Αφαίρεση» που παραδίδει σωστά. Ο διαχειριστής όμως έχει
 * μπροστά του και τον προφανή δρόμο — Χρήστες → Διαγραφή — που ρωτά τι να κάνει
 * με τα **άρθρα** του και δεν ξέρει τίποτα για τους πίνακες του CRM. Έλεγχος
 * λειτουργίας, εύρημα 5.
 *
 * Το αρχείο δοκιμάζει και τα δύο άκρα του κανόνα: τι αλλάζει χέρια επειδή είναι
 * ζωντανή δουλειά, και τι **δεν** αλλάζει επειδή είναι αρχείο. Χωρίς το δεύτερο,
 * μια υλοποίηση που μετακινεί τα πάντα θα περνούσε — και θα ξανάγραφε ιστορία
 * για να βολέψει ένα ξένο κλειδί.
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
use WP_User;

final class DepartingUserTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private TeamRepository $team;

    private int $manager;

    private int $member;

    protected function setUp(): void
    {
        parent::setUp();

        require_once ABSPATH . 'wp-admin/includes/user.php';

        $this->contracts = new ContractRepository();
        $this->team      = new TeamRepository();

        $this->manager = $this->makeCrmUser(Roles::PARTNER);
        $this->member  = $this->makeCrmUser(Roles::SELLER);

        $this->team->attach($this->member, $this->manager);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    // --- ό,τι είναι ζωντανή δουλειά αλλάζει χέρια --------------------------

    public function testTheirContractsGoToTheirManager(): void
    {
        $contractId = $this->contractOf($this->member);

        wp_delete_user($this->member);

        self::assertSame(
            $this->manager,
            (int) $this->storedRow('contracts', $contractId)['partner_user_id']
        );
    }

    public function testTheirTeamGoesToTheirManager(): void
    {
        $grandchild = $this->makeCrmUser(Roles::SELLER);
        $this->team->attach($grandchild, $this->member);

        wp_delete_user($this->member);

        self::assertTrue($this->team->reportsDirectlyTo($grandchild, $this->manager));
    }

    public function testTheirLeadsGoToTheirManager(): void
    {
        $leadId = $this->leadOf($this->member);

        wp_delete_user($this->member);

        self::assertSame(
            $this->manager,
            (int) $this->storedRow('leads', $leadId)['partner_user_id']
        );
    }

    public function testTheirOpenTasksGoToTheirManager(): void
    {
        $taskId = $this->taskFor($this->member, 'open');

        wp_delete_user($this->member);

        self::assertSame(
            $this->manager,
            (int) $this->storedRow('tasks', $taskId)['assigned_to']
        );
    }

    // --- ό,τι είναι αρχείο μένει ------------------------------------------

    /**
     * Ολοκληρωμένη εργασία δεν αλλάζει χέρια.
     *
     * Λέει «αυτός ο άνθρωπος έκανε αυτό, τότε». Μεταφέροντάς την θα λέγαμε ότι
     * το έκανε κάποιος άλλος.
     */
    public function testACompletedTaskKeepsTheNameOfWhoDidIt(): void
    {
        $taskId = $this->taskFor($this->member, 'done');
        $member = $this->member;

        wp_delete_user($this->member);

        self::assertSame($member, (int) $this->storedRow('tasks', $taskId)['assigned_to']);
    }

    // --- ο διάδοχος όταν δεν υπάρχει προϊστάμενος --------------------------

    /** Χωρίς από πάνω, τα παίρνει ο διαχειριστής που διαγράφει. */
    public function testWithoutAManagerTheDeletingAdministratorInherits(): void
    {
        $admin = $this->makeAdministrator();
        wp_set_current_user($admin);

        $loner      = $this->makeCrmUser(Roles::PARTNER);
        $contractId = $this->contractOf($loner);

        wp_delete_user($loner);

        self::assertSame(
            $admin,
            (int) $this->storedRow('contracts', $contractId)['partner_user_id']
        );
    }

    /**
     * Προϊστάμενος που δεν υπάρχει δεν μετράει για διάδοχος.
     *
     * Το `ecrm_parent` δείχνει σε id που δεν αντιστοιχεί σε χρήστη. Δεν είναι
     * υποθετικό: εγκατάσταση που πέρασε από εισαγωγή, ή χρήστης σβησμένος πριν
     * υπάρξει αυτή η κλάση, αφήνουν ακριβώς τέτοιες γραμμές. Χωρίς τον έλεγχο
     * ύπαρξης, η δουλειά θα παραδιδόταν σε δεύτερο φάντασμα και θα ήταν
     * χειρότερα από το να μην είχε γίνει τίποτα — γιατί θα φαινόταν ότι έγινε.
     *
     * Γραμμένο απευθείας στα meta και όχι με δεύτερη διαγραφή: μια δεύτερη
     * διαγραφή θα διόρθωνε μόνη της το `ecrm_parent` του μέλους, οπότε ο έλεγχος
     * που δοκιμάζεται εδώ δεν θα εκτελούνταν ποτέ.
     */
    public function testAManagerWhoNoLongerExistsIsNotAValidSuccessor(): void
    {
        $admin = $this->makeAdministrator();
        wp_set_current_user($admin);

        update_user_meta($this->member, TeamRepository::PARENT_META, 999999);

        $contractId = $this->contractOf($this->member);

        wp_delete_user($this->member);

        self::assertSame(
            $admin,
            (int) $this->storedRow('contracts', $contractId)['partner_user_id']
        );
    }

    // --- fixtures ----------------------------------------------------------

    private function makeAdministrator(): int
    {
        $userId = $this->makePartner();
        $user   = get_user_by('id', $userId);

        self::assertInstanceOf(WP_User::class, $user);

        $user->set_role('administrator');

        return $userId;
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
