<?php

/**
 * Καμία σύμβαση δεν φτάνει σε φυλασσόμενη κατάσταση χωρίς χαρτιά και υπογραφή
 * — από καμία πόρτα.
 *
 * ## Τι έσπαγε (07/09/2026)
 *
 * Ο έλεγχος δικαιολογητικών υπήρχε, αλλά ζούσε σε **δύο** controllers:
 * `ContractStatusController:179` και `ContractsBulkController:154`. Ο τρίτος
 * δρόμος που γράφει `status` — η αποθήκευση της φόρμας — δεν τον καλούσε, και
 * ούτε ο ίδιος ο `ContractLifecycle`, απ' όπου περνούν το cron και η εισαγωγή
 * Excel (εύρημα ελέγχου #7, 18/08).
 *
 * Ο ρόλος **Πωλητής** έχει `CHANGE_STATUS` (`Roles.php:109`), ο γράφος
 * επιτρέπει `presale → registration → ... → finalisation → active`, και το
 * `isPayable()` περιλαμβάνει το `active`: αποθηκεύσεις της φόρμας πάνω στη
 * δική του σύμβαση και η προμήθεια κλείδωνε, με μηδέν δικαιολογητικά και
 * χωρίς ο πελάτης να έχει υπογράψει ποτέ. Δεν είναι θεωρητικό σενάριο — το
 * περιέγραψε ο υπεύθυνος του δικτύου πριν βρεθεί στον κώδικα.
 *
 * ## Γιατί δοκιμάζονται και οι δύο πόρτες
 *
 * Ίδιο σκεπτικό με το `CancelAfterActiveTest`: κανόνας που τον τηρούν τρεις
 * στις τέσσερις πόρτες δεν είναι κανόνας. Εδώ δοκιμάζονται ο `ContractLifecycle`
 * (cron, εισαγωγή, κάθε μελλοντική διαδρομή) και η αποθήκευση της φόρμας, που
 * ήταν η ανοιχτή.
 *
 * ## Και η αντίθετη περίπτωση, που είναι εξίσου σημαντική
 *
 * Μια πύλη που μπλοκάρει τα πάντα θα περνούσε κάθε αρνητικό test και θα άφηνε
 * τη σουίτα πράσινη. Γι' αυτό υπάρχει και ο έλεγχος ότι οι **μη**
 * φυλασσόμενες καταστάσεις προχωρούν κανονικά.
 *
 * ## Γιατί μερικά fixtures γράφουν το status απευθείας στη βάση
 *
 * Το `gate_statuses()` είναι `['finalisation', 'active']` -- και οι δύο
 * φυλάσσονται. Αυτό σημαίνει ότι δεν μπορείς να φτιάξεις με το χέρι, μέσω
 * `moveTo()`, μια σύμβαση ήδη σε `finalisation` ΧΩΡΙΣ χαρτιά -- η ίδια η
 * είσοδος θα την είχε ήδη αρνηθεί. Όπου το test θέλει «σύμβαση που ΕΙΝΑΙ ήδη
 * εκεί, χωρίς χαρτιά» (π.χ. για να δοκιμάσει το επόμενο βήμα προς `active`),
 * το status γράφεται απευθείας στη βάση -- αντιπροσωπεύει μια γραμμή που
 * έφτασε εκεί πριν υπάρξει η πύλη, ή από εισαγωγή Excel.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Infrastructure\PaperworkGate;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;
use WP_REST_Request;

final class PaperworkGateTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private ContractLifecycle $lifecycle;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->lifecycle = Services::lifecycle();

        $this->user = $this->makeCrmUser(Roles::SELLER);

        wp_set_current_user($this->user);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    // --- η πόρτα του ContractLifecycle (cron, εισαγωγή) ---------------------

    public function testActiveIsRefusedWithoutPaperwork(): void
    {
        $contractId = $this->finalisationContractWithoutPaperwork();

        self::assertFalse($this->lifecycle->moveTo($contractId, 'active'));
        self::assertSame('finalisation', $this->statusOf($contractId));
    }

    public function testFinalisationIsRefusedWithoutPaperworkToo(): void
    {
        $contractId = $this->awaitingSignatureContract();

        self::assertFalse($this->lifecycle->moveTo($contractId, 'finalisation'));
        self::assertSame('awaiting_signature', $this->statusOf($contractId));
    }

    /**
     * Η αντίθετη περίπτωση: μη φυλασσόμενη κατάσταση δεν αγγίζεται από την
     * πύλη. Χωρίς αυτό, μια πύλη που αρνείται τα πάντα θα περνούσε τα δύο
     * παραπάνω και θα φαινόταν σωστή.
     */
    public function testAnUngatedStatusStillMovesFreely(): void
    {
        $contractId = $this->submittedContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'registration'));
        self::assertSame('registration', $this->statusOf($contractId));
    }

    // --- η πόρτα της φόρμας, που ήταν η ανοιχτή -----------------------------

    /**
     * Το σενάριο αυτολεξεί: ο πωλητής στέλνει `status=active` στη δική του
     * σύμβαση μέσα από την αποθήκευση της φόρμας, από ένα σημείο όπου ο
     * γράφος θα το επέτρεπε (`finalisation`) -- ώστε ό,τι το μπλοκάρει να
     * είναι αποκλειστικά η PaperworkGate, όχι ο γράφος.
     */
    public function testTheSaveFormCannotJumpStraightToActive(): void
    {
        $contractId = $this->finalisationContractWithoutPaperwork();

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts');
        $request->set_param('contract_id', $contractId);
        $request->set_param('status', 'active');

        $response = rest_do_request($request);

        self::assertSame(422, $response->get_status());
        self::assertSame('finalisation', $this->statusOf($contractId));
    }

    /** Ο λόγος φτάνει στον χρήστη — δεν είναι σιωπηλή άρνηση. */
    public function testTheRefusalSaysWhy(): void
    {
        $contractId = $this->finalisationContractWithoutPaperwork();

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts');
        $request->set_param('contract_id', $contractId);
        $request->set_param('status', 'active');

        $data  = (array) rest_do_request($request)->get_data();
        $error = (string) ($data['error'] ?? '');

        self::assertNotSame('', $error);
        self::assertTrue(
            str_starts_with($error, PaperworkGate::MISSING_DOCS)
                || str_starts_with($error, PaperworkGate::EXPIRED_DOCS)
                || $error === PaperworkGate::NOT_SIGNED,
            'Η άρνηση πρέπει να λέει τι λείπει, όχι απλώς ότι απέτυχε: ' . $error
        );
    }

    // --- fixtures ----------------------------------------------------------

    private function submittedContract(): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'presale', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->user)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }

    /** Φτάνει σε `awaiting_signature` περνώντας νόμιμα από τον γράφο -- καμία από τις δύο δεν είναι φυλασσόμενη. */
    private function awaitingSignatureContract(): int
    {
        $contractId = $this->submittedContract();

        self::assertTrue($this->lifecycle->moveTo($contractId, 'registration'));
        self::assertTrue($this->lifecycle->moveTo($contractId, 'awaiting_signature'));

        return $contractId;
    }

    /**
     * Ήδη σε `finalisation`, χωρίς χαρτιά ή υπογραφή -- γραμμένο απευθείας
     * στη βάση, γιατί η ίδια η είσοδος στο `finalisation` είναι φυλασσόμενη
     * (βλ. σχόλιο πάνω από την κλάση).
     */
    private function finalisationContractWithoutPaperwork(): int
    {
        $contractId = $this->submittedContract();

        global $wpdb;
        $wpdb->update(Tables::name('contracts'), ['status' => 'finalisation'], ['id' => $contractId]);

        return $contractId;
    }

    private function statusOf(int $contractId): string
    {
        return (string) $this->storedRow('contracts', $contractId)['status'];
    }
}
