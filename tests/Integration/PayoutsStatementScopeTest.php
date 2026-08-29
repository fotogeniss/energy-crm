<?php

/**
 * `GET /payouts/{id}/statement` — η βεβαίωση εκκαθάρισης ενός συνεργάτη.
 *
 * AUDIT εύρημα 2.5 (EKKREMI-29-08.html): «χειροποίητος έλεγχος εμβέλειας (το
 * λέει το ίδιο του το docblock), μηδέν tests. Χρήματα.» Το ίδιο το
 * PayoutsController.php το λέει καθαρά στο δικό του docblock: αυτή η
 * διαδρομή υπάρχει ξεχωριστά από το wp-admin `ECRM_Payouts::pdf()` ΑΚΡΙΒΩΣ
 * επειδή εκείνος ο χειριστής δεν ελέγχει ποιανού είναι η παρτίδα -- μόνο αν
 * ο χρήστης είναι διαχειριστής. Αν αυτή η διαδρομή χαλαρώσει ποτέ κατά λάθος
 * στο ίδιο, κάθε συνεργάτης θα κατέβαζε τη βεβαίωση αμοιβών οποιουδήποτε
 * άλλου αλλάζοντας το id στο URL -- οικονομικό δεδομένο, όχι απλή διαρρος.
 *
 * Ο έλεγχος είναι μία γραμμή (`$scope->includes(...)`, πριν χτιστεί
 * οποιοδήποτε byte PDF) και ήταν χωρίς κανένα test. Αυτό το αρχείο τον
 * κλειδώνει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class PayoutsStatementScopeTest extends IntegrationTestCase
{
    private int $owner;

    private int $payoutId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner    = $this->makeCrmUser(Roles::SELLER);
        $this->payoutId = $this->batch($this->owner);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** The owner gets a real PDF, not just ok:true. */
    public function testTheOwnerCanDownloadTheirOwnStatement(): void
    {
        wp_set_current_user($this->owner);

        $response = $this->statement($this->payoutId);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertTrue($data['ok']);
        self::assertSame('application/pdf', $data['mime']);
        self::assertStringStartsWith('%PDF-', base64_decode((string) $data['b64'], true) ?: '');
    }

    /**
     * The exact scenario the controller's own docblock exists to prevent:
     * another partner changing `id` in the URL. Must read as "not found",
     * not as "yes, that exists, but it's not yours" -- the docblock is
     * explicit that the two must be indistinguishable.
     */
    public function testAnotherPartnerCannotReachItByGuessingTheId(): void
    {
        $stranger = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($stranger);

        $response = $this->statement($this->payoutId);

        self::assertSame(404, $response->get_status());
        self::assertSame('Δεν βρέθηκε.', $response->get_data()['error']);
    }

    /** Control: a genuinely missing id must produce the identical response. */
    public function testANonexistentIdProducesTheIdenticalResponse(): void
    {
        $stranger = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($stranger);

        $real    = $this->statement($this->payoutId);
        $missing = $this->statement(999999999);

        self::assertSame($real->get_status(), $missing->get_status());
        self::assertSame($real->get_data(), $missing->get_data());
    }

    /**
     * Control the other direction: a manager with the owner in their
     * downline is not "another partner" -- proves the gate is scope, not a
     * blanket "only the exact owner" check that would also break for
     * legitimate team visibility.
     */
    public function testAManagerWithTheOwnerInTheirDownlineCanDownloadIt(): void
    {
        $manager = $this->makeCrmUser(Roles::PARTNER);

        update_user_meta($this->owner, NetworkRepository::PARENT_META, $manager);
        (new NetworkRepository())->rebuild($this->owner);

        wp_set_current_user($manager);

        $response = $this->statement($this->payoutId);

        self::assertSame(200, $response->get_status());
        self::assertTrue($response->get_data()['ok']);
    }

    private function batch(int $partnerId): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PAYOUTS), [
            'partner_user_id' => $partnerId,
            'period'          => '2026-08',
            'cnt'             => 2,
            'amount'          => 84.50,
            'status'          => 'pending',
        ]);

        $id = (int) $wpdb->insert_id;
        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function statement(int $payoutId): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/ecrm/v1/payouts/' . $payoutId . '/statement');
        $request->set_url_params(['id' => $payoutId]);

        return rest_do_request($request);
    }
}
