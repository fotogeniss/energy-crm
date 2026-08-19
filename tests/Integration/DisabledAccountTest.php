<?php

/**
 * Τι σημαίνει στην πράξη «απενεργοποιημένος λογαριασμός».
 *
 * Ως τις 18/08/2026 σήμαινε μια ετικέτα. Η σημαία `ecrm_disabled` γραφόταν από
 * δύο σημεία και διαβαζόταν από δύο, και τα δύο για να τη ζωγραφίσουν στην
 * οθόνη. Ο συνεργάτης που «αφαιρέθηκε» συνέχιζε να συνδέεται, να βλέπει ΑΦΜ,
 * ΑΔΤ και σαρωμένες ταυτότητες, και να τραβά εξαγωγή σε Excel.
 *
 * Το αρχείο δοκιμάζει και τις δύο πλευρές, γιατί η μία χωρίς την άλλη αφήνει
 * ακέραιο το σενάριο που μας ενδιαφέρει: το `authenticate` δεν τον αφήνει να
 * ξαναμπεί, το `user_has_cap` τον αδειάζει ενώ είναι **ήδη** μέσα. Άνθρωπος που
 * απενεργοποιήθηκε με ανοιχτή τη συνεδρία του δεν ξαναπερνά από login.
 *
 * Και δοκιμάζει τα όρια, που είναι εξίσου σημαντικά: ο διαχειριστής δεν
 * κλειδώνεται ποτέ έξω, και τα δικαιώματα που δεν είναι του CRM δεν αγγίζονται.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\TeamRepository;
use WP_Error;
use WP_REST_Request;
use WP_User;

final class DisabledAccountTest extends IntegrationTestCase
{
    private TeamRepository $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = new TeamRepository();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    // --- τα δικαιώματα -----------------------------------------------------

    /** Ο ενεργός συνεργάτης δουλεύει, αλλιώς το test από κάτω δεν λέει τίποτα. */
    public function testAnActivePartnerKeepsTheirCapabilities(): void
    {
        $userId = $this->makeCrmUser(Roles::SELLER);

        self::assertTrue(user_can($userId, Capability::USE_CRM));
        self::assertTrue(user_can($userId, Capability::CREATE_CONTRACT));
    }

    /** Απενεργοποιημένος: κανένα δικαίωμα του CRM, πουθενά. */
    public function testADisabledPartnerLosesEveryCrmCapability(): void
    {
        $userId = $this->makeCrmUser(Roles::SELLER);

        $this->team->setDisabled($userId, true);

        foreach (Capability::all() as $capability) {
            self::assertFalse(
                user_can($userId, $capability),
                'Κρατά ακόμη το ' . $capability
            );
        }
    }

    /**
     * Και ό,τι δεν είναι του CRM μένει άθικτο.
     *
     * Η σημαία λέει «εκτός CRM», όχι «εκτός WordPress». Ένα φίλτρο που θα
     * άδειαζε τα πάντα θα έσπαγε τον λογαριασμό με τρόπους που δεν αφορούν
     * αυτό το plugin.
     */
    public function testADisabledPartnerCanStillReadTheSite(): void
    {
        $userId = $this->makeCrmUser(Roles::SELLER);

        $this->team->setDisabled($userId, true);

        self::assertTrue(user_can($userId, 'read'));
    }

    /** Η πραγματική συνέπεια: οι διαδρομές του CRM κλείνουν. */
    public function testADisabledPartnerIsTurnedAwayFromTheApi(): void
    {
        $userId = $this->makeCrmUser(Roles::SELLER);

        wp_set_current_user($userId);

        self::assertSame(200, rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/dashboard'))->get_status());

        $this->team->setDisabled($userId, true);

        self::assertSame(
            403,
            rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/dashboard'))->get_status()
        );
    }

    /**
     * Ο διαχειριστής δεν κλειδώνεται ποτέ έξω.
     *
     * Χωρίς αυτή την εξαίρεση, ένα λάθος κλικ σε λογαριασμό διαχειριστή είναι
     * μη αναστρέψιμο: το ξεκλείδωμα απαιτεί ακριβώς τα δικαιώματα που χάθηκαν.
     */
    public function testAnAdministratorIsNeverLockedOut(): void
    {
        $adminId = $this->makeAdministrator();

        $this->team->setDisabled($adminId, true);

        self::assertTrue(user_can($adminId, Capability::USE_CRM));
    }

    // --- η σύνδεση ---------------------------------------------------------

    /** Δεν ξαναμπαίνει. */
    public function testADisabledUserCannotLogIn(): void
    {
        $userId = $this->makeCrmUser(Roles::SELLER);

        $this->team->setDisabled($userId, true);

        $answer = $this->authenticate($userId);

        self::assertInstanceOf(WP_Error::class, $answer);
        self::assertSame('ecrm_account_disabled', $answer->get_error_code());
    }

    /** Ο ενεργός μπαίνει — αλλιώς το παραπάνω θα περνούσε με σπασμένο login. */
    public function testAnActiveUserStillLogsIn(): void
    {
        $userId = $this->makeCrmUser(Roles::SELLER);

        self::assertInstanceOf(WP_User::class, $this->authenticate($userId));
    }

    /** Και ο διαχειριστής μπαίνει, ακόμη κι αν κάποιος τον σημείωσε. */
    public function testADisabledAdministratorStillLogsIn(): void
    {
        $adminId = $this->makeAdministrator();

        $this->team->setDisabled($adminId, true);

        self::assertInstanceOf(WP_User::class, $this->authenticate($adminId));
    }

    /** Διαχειριστής του site, με τον ίδιο τρόπο που η βάση φτιάχνει συνεργάτες. */
    private function makeAdministrator(): int
    {
        $userId = $this->makePartner();
        $user   = get_user_by('id', $userId);

        self::assertInstanceOf(WP_User::class, $user);

        $user->set_role('administrator');

        return $userId;
    }

    /**
     * Η απάντηση του φίλτρου `authenticate` για έναν χρήστη.
     *
     * Το φίλτρο και όχι η `wp_signon()`: εκείνη θέλει κωδικό σε καθαρό κείμενο,
     * στέλνει cookies και αγγίζει την κατάσταση της συνεδρίας. Αυτό που
     * προστέθηκε είναι ένα φίλτρο, και εδώ δοκιμάζεται ακριβώς αυτό —
     * περασμένο τον χρήστη που θα του έδινε το WordPress αφού έχει ήδη
     * επαληθεύσει τον κωδικό.
     *
     * @return WP_User|WP_Error|null
     */
    private function authenticate(int $userId)
    {
        $user = get_user_by('id', $userId);

        self::assertInstanceOf(WP_User::class, $user);

        return apply_filters('authenticate', $user, $user->user_login, '');
    }
}
