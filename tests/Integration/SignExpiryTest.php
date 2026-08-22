<?php

/**
 * Η λήξη του παραθύρου υπογραφής — και τι ΔΕΝ λήγει μαζί του (§6γ 7).
 *
 * ## Η διάκριση που ελέγχεται εδώ
 *
 * Ο σύνδεσμος υπογραφής **είναι** ο σύνδεσμος παρακολούθησης: ίδιο URL, ίδιο
 * token, και το `{track}` μπαίνει σε **όλα** τα πρότυπα μηνυμάτων — και σε
 * εκείνο της δρομολόγησης, που ο πελάτης ανοίγει βδομάδες αργότερα. Λήξη στο
 * `verify()` θα σκότωνε το tracking μαζί με την υπογραφή.
 *
 * Άρα λήγει η **δυνατότητα υπογραφής**, όχι ο σύνδεσμος. Ο πιο σημαντικός
 * έλεγχος αυτού του αρχείου είναι ο τελευταίος: ότι μετά τη λήξη η σελίδα
 * **εξακολουθεί να απαντά** με την κατάσταση.
 *
 * ## Το ρολόι, και γιατί το «καμία ημερομηνία» σημαίνει «καμία προθεσμία»
 *
 * Μετράει από το τελευταίο `sign_sent_*`, που γράφεται σε κάθε αποστολή (79).
 * Σύμβαση χωρίς τέτοιο γεγονός πήρε σύνδεσμο πριν αρχίσουν οι καταγραφές — και
 * ένα SMS που έχει ήδη φύγει δεν πεθαίνει επειδή αλλάξαμε εμείς κώδικα. Αυτό
 * ακριβώς κρατούσε το §6γ (7) ανοιχτό, και είναι ο πρώτος έλεγχος εδώ.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Tracking;
use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;

final class SignExpiryTest extends IntegrationTestCase
{
    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $seller = $this->makeCrmUser(Roles::SELLER);

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $seller,
            'status'          => 'pending_signature',
            'code'            => 'ΕΝ-EXP-1',
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
        ]);

        $this->contractId = (int) $wpdb->insert_id;

        wp_set_current_user($seller);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * Καταγράφει αποστολή N ωρών πριν, ΣΧΕΤΙΚΑ με το τώρα της βάσης.
     *
     * Σχετικά και όχι με καρφωμένη ημερομηνία, για τον ίδιο λόγο που το κάνει
     * και το DashboardCardsTest: η σύγκριση γίνεται με `NOW()` της βάσης, και
     * μια σταθερή ημερομηνία θα έδινε έλεγχο που σαπίζει κάθε μέρα που περνά.
     */
    private function sentHoursAgo(int $hours): void
    {
        global $wpdb;

        (new EventRepository())->record($this->contractId, 0, 'sign_sent_sms', ['message' => 'δοκιμή']);

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET created_at = NOW() - INTERVAL %d HOUR WHERE contract_id = %d ORDER BY id DESC LIMIT 1',
                Tables::name(Tables::EVENTS),
                $hours,
                $this->contractId
            )
        );
    }

    /** @return array<string, mixed> */
    private function trackPayload(): array
    {
        $token = ECRM_Tracking::token($this->contractId);

        self::assertNotSame('', $token, 'Χωρίς token δεν ελέγχεται τίποτα από τα παρακάτω.');

        return (array) rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/track/' . $token))->get_data();
    }

    // ── Το μεταβατικό: καμία ημερομηνία → καμία προθεσμία ────────────────

    public function testAContractWithNoRecordedSendNeverExpires(): void
    {
        // Ο σύνδεσμος στάλθηκε πριν αρχίσουν οι καταγραφές. Το SMS που έχει
        // ήδη φύγει δουλεύει — αυτό ζητούσε το §6γ (7), και γι' αυτό δεν
        // χρειάστηκε ούτε backfill ούτε ημερομηνία-μαχαίρι.
        self::assertFalse(ECRM_Tracking::sign_expired($this->contractId));
        self::assertTrue($this->trackPayload()['can_sign']);
    }

    public function testTheAbsenceOfAnEventIsNotZeroHours(): void
    {
        $hours = (new EventRepository())->hoursSinceLastOfTypes($this->contractId, ['sign_sent_sms']);

        // null και 0 λένε διαφορετικά πράγματα: «ποτέ» και «μόλις τώρα».
        self::assertNull($hours);
    }

    // ── Το παράθυρο ──────────────────────────────────────────────────────

    public function testInsideTheWindowTheCustomerCanStillSign(): void
    {
        $this->sentHoursAgo(10);

        self::assertFalse(ECRM_Tracking::sign_expired($this->contractId));

        $payload = $this->trackPayload();

        self::assertTrue($payload['can_sign']);
        self::assertFalse($payload['sign_expired']);
    }

    public function testPastTheWindowTheSignaturePanelIsGone(): void
    {
        $this->sentHoursAgo(ECRM_Tracking::SIGN_WINDOW_HOURS + 1);

        self::assertTrue(ECRM_Tracking::sign_expired($this->contractId));

        $payload = $this->trackPayload();

        self::assertFalse($payload['can_sign']);
        self::assertTrue($payload['sign_expired']);
    }

    /**
     * Ο ΠΙΟ ΣΗΜΑΝΤΙΚΟΣ ΕΛΕΓΧΟΣ ΤΟΥ ΑΡΧΕΙΟΥ.
     *
     * Αν κάποτε μπει λήξη στο `verify()` αντί εδώ, αυτός θα κοκκινίσει — και θα
     * είναι το μόνο πράγμα που θα σταματήσει έναν πελάτη από το να βρει
     * «ο σύνδεσμος δεν είναι έγκυρος» ανοίγοντας τον Δεκέμβριο το SMS της
     * ενεργοποίησης, για σύμβαση που δουλεύει μια χαρά.
     */
    public function testTheTrackingPageStillAnswersAfterTheSignatureExpired(): void
    {
        $this->sentHoursAgo(ECRM_Tracking::SIGN_WINDOW_HOURS + 240);

        $payload = $this->trackPayload();

        self::assertTrue($payload['ok'], 'Η παρακολούθηση ΔΕΝ λήγει μαζί με την υπογραφή.');

        // `status_label` και `stage`, ΟΧΙ `status`: η δημόσια σελίδα δεν
        // εκθέτει ποτέ το εσωτερικό slug. Η πρώτη γραφή αυτού του ελέγχου
        // ζητούσε `status` — κλειδί που δεν υπάρχει — δηλαδή διεκδικούσε σχήμα
        // από μνήμη αντί να το διαβάσει. Έπεσε, και καλώς.
        self::assertArrayHasKey('status_label', $payload);
        self::assertArrayHasKey('stage', $payload);
        self::assertSame('ΕΝ-EXP-1', $payload['code']);
    }

    // ── Το «Ξαναστείλε» ανοίγει ξανά το παράθυρο ─────────────────────────

    public function testASecondSendResetsTheClock(): void
    {
        $this->sentHoursAgo(ECRM_Tracking::SIGN_WINDOW_HOURS + 5);
        self::assertTrue(ECRM_Tracking::sign_expired($this->contractId));

        // Ακριβώς αυτό κάνει το «Ξαναστείλε» του διαλόγου: νέα αποστολή, νέο
        // γεγονός. Το ρολόι διαβάζει το ΤΕΛΕΥΤΑΙΟ, όχι το πρώτο.
        $this->sentHoursAgo(0);

        self::assertFalse(ECRM_Tracking::sign_expired($this->contractId));
        self::assertTrue($this->trackPayload()['can_sign']);
    }

    // ── Ο server επιβάλλει, δεν συμβουλεύει ──────────────────────────────

    public function testAStaleTabCannotSignAfterTheWindowClosed(): void
    {
        $this->sentHoursAgo(ECRM_Tracking::SIGN_WINDOW_HOURS + 2);

        $response = $this->attemptSign('data:image/png;base64,' . base64_encode('ούτε καν PNG'));

        // 410 και όχι 400: ο σύνδεσμος ΗΤΑΝ έγκυρος, απλώς όχι πια. Και το
        // μήνυμα λέει τι να κάνει ο πελάτης, όχι μόνο ότι απέτυχε.
        self::assertSame(410, $response->get_status());
        self::assertTrue($response->get_data()['expired']);
    }

    /**
     * Ο λόγος που δίνεται είναι ο ΠΡΑΓΜΑΤΙΚΟΣ, όχι ο πρώτος που βρέθηκε.
     *
     * Αυτός ο έλεγχος γεννήθηκε από αποτυχία του από πάνω. Ο έλεγχος λήξης
     * ήταν αρχικά κάτω από την επικύρωση του φορτίου, οπότε ληγμένος σύνδεσμος
     * με κακή υπογραφή απαντούσε «Μη έγκυρη υπογραφή»: ο πελάτης μάθαινε για
     * το PNG του αντί για τον λόγο που δεν μπορεί να υπογράψει. Ίδιο και με
     * ξεχασμένη συναίνεση — γι' αυτό ελέγχονται και τα δύο.
     */
    public function testTheExpiryIsReportedBeforeAnyComplaintAboutThePayload(): void
    {
        $this->sentHoursAgo(ECRM_Tracking::SIGN_WINDOW_HOURS + 2);

        $noConsent = $this->attemptSign('data:image/png;base64,' . base64_encode('σκουπίδια'), false);

        self::assertSame(410, $noConsent->get_status());
        self::assertTrue($noConsent->get_data()['expired']);
    }

    /**
     * Και το αντίστροφο: ΜΕΣΑ στο παράθυρο, τα παράπονα για το φορτίο
     * επιστρέφουν κανονικά. Χωρίς αυτό, η αναδιάταξη παραπάνω θα μπορούσε να
     * έχει καταπιεί κάθε άλλο σφάλμα και ο έλεγχος από πάνω θα περνούσε.
     */
    public function testInsideTheWindowABadSignatureIsStillABadSignature(): void
    {
        $this->sentHoursAgo(1);

        $response = $this->attemptSign('data:image/png;base64,' . base64_encode('σκουπίδια'));

        self::assertSame(400, $response->get_status());
    }

    private function attemptSign(string $dataUrl, bool $consent = true): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/track/' . ECRM_Tracking::token($this->contractId) . '/sign');
        $request->set_param('signature', $dataUrl);
        $request->set_param('consent', $consent);

        return rest_do_request($request);
    }

    public function testTheWindowIsOneNumberInOnePlace(): void
    {
        // Αν αύριο αποφασιστεί 72 ώρες ή 7 μέρες, αλλάζει ΜΟΝΟ αυτή η σταθερά.
        // Ο έλεγχος υπάρχει ώστε κανείς να μη σπείρει το 48 σε τρία αρχεία.
        self::assertSame(48, ECRM_Tracking::SIGN_WINDOW_HOURS);
    }
}
