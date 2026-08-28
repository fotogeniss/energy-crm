<?php

/**
 * `POST /intake/{token}` — η δημόσια υποβολή στοιχείων του πελάτη.
 *
 * Μηδέν αυτόματη κάλυψη υπήρχε εδώ ως τις 28/08/2026, και όλα τα σφάλματα
 * της (156) βρέθηκαν σε αυτή ακριβώς τη σελίδα — από τον ιδιοκτήτη, ζωντανά,
 * όχι από δοκιμή. Αυτό το αρχείο καλύπτει τον κανόνα που έμεινε πίσω από τα
 * σφάλματα εκείνης της μέρας: την **ιδεμποτεντία** (ίδιος πωλητής + ίδιο
 * κινητό + ακόμα «νέος» + αμετάτρεπτος + μέσα σε 12 ώρες → ίδιος υποψήφιος,
 * όχι διπλός) και τους φύλακες γύρω της.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Intake;
use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\TeamRepository;
use WP_REST_Request;

final class IntakeSubmitTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Η βασική διαδρομή: κινητό + συναίνεση φτιάχνουν υποψήφιο. */
    public function testAValidSubmissionCreatesALead(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $response = $this->submit($partner, ['phone' => '6912345678', 'consent' => true]);

        self::assertSame(200, $response->get_status());
        self::assertTrue($response->get_data()['ok'] ?? false);
        self::assertNotSame('', $response->get_data()['ref'] ?? '', 'Λείπει το ref του υποψηφίου.');

        $lead = $this->findLead($partner);
        self::assertNotNull($lead, 'Δεν βρέθηκε γραμμή leads για τον πωλητή.');
        self::assertSame('306912345678', $lead['phone'], 'Το κινητό δεν κανονικοποιήθηκε.');
        self::assertSame('link', $lead['source']);
        self::assertSame('new', $lead['stage']);
        self::assertNotNull($lead['consent_at'], 'Η συναίνεση δεν καταγράφηκε.');
    }

    /** Χωρίς κινητό δεν υπάρχει τίποτα να καλέσει ο πωλητής. */
    public function testAnEmptyPhoneIsRejected(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $response = $this->submit($partner, ['phone' => '', 'consent' => true]);

        self::assertSame(400, $response->get_status());
        self::assertNull($this->findLead($partner));
    }

    /** Ασυνάρτητο κινητό κανονικοποιείται σε '' από το ECRM_Messaging και απορρίπτεται εδώ. */
    public function testAnUnrecognisablePhoneIsRejected(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $response = $this->submit($partner, ['phone' => '123', 'consent' => true]);

        self::assertSame(400, $response->get_status());
        self::assertNull($this->findLead($partner));
    }

    /** Δεν αποθηκεύεται τίποτα χωρίς ρητή συναίνεση — έγγραφα ιδιωτών από δημόσια σελίδα. */
    public function testMissingConsentIsRejected(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $response = $this->submit($partner, ['phone' => '6912345678', 'consent' => false]);

        self::assertSame(400, $response->get_status());
        self::assertNull($this->findLead($partner));
    }

    /** Token που δεν επαληθεύεται καν δεν αποκαλύπτει τίποτα -- 404, όχι 400. */
    public function testAnInvalidTokenIsRefused(): void
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/intake/1-' . str_repeat('0', 20));
        $request->set_body_params(['phone' => '6912345678', 'consent' => true]);

        $response = rest_do_request($request);

        self::assertSame(404, $response->get_status());
    }

    /**
     * Πωλητής που απενεργοποιήθηκε δεν έχει πια δουλεύοντα σύνδεσμο, χωρίς
     * ανάκληση -- ίδιο 404 με άκυρο token, ώστε να μη διαρρέει ότι το token
     * ήταν κάποτε έγκυρο.
     */
    public function testADisabledPartnersLinkIsRefused(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);
        (new TeamRepository())->setDisabled($partner, true);

        $response = $this->submit($partner, ['phone' => '6912345678', 'consent' => true]);

        self::assertSame(404, $response->get_status());
    }

    /**
     * Η καρδιά της (156): ίδιος πωλητής, ίδιο κινητό, μέσα σε 12 ώρες, ακόμα
     * «νέος» -- επιστρέφεται ο ΙΔΙΟΣ υποψήφιος, όχι δεύτερος. Χωρίς αυτό, ο
     * πελάτης που ξαναμπαίνει (π.χ. να στείλει την ταυτότητα που ξέχασε)
     * φτιάχνει δεύτερη γραμμή που κανείς δεν συνδέει με την πρώτη.
     */
    public function testTheSameSubmissionWithinTheWindowReturnsTheSameLead(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $first  = $this->submit($partner, ['phone' => '6912345678', 'consent' => true]);
        $second = $this->submit($partner, ['phone' => '6912345678', 'consent' => true]);

        self::assertSame(200, $first->get_status());
        self::assertSame(200, $second->get_status());
        self::assertSame(
            $first->get_data()['ref'],
            $second->get_data()['ref'],
            'Δεύτερη υποβολή με το ίδιο κινητό έφτιαξε δεύτερο υποψήφιο.'
        );

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE partner_user_id = %d AND phone = %s',
            \EnergyCRM\Persistence\Tables::name(\EnergyCRM\Persistence\Tables::LEADS),
            $partner,
            '306912345678'
        ));
        self::assertSame(1, $count, 'Υπάρχουν δύο γραμμές leads για την ίδια υποβολή.');
    }

    /** Διαφορετικό κινητό, ίδιος πωλητής: δεν πρέπει να ενωθεί με τον προηγούμενο υποψήφιο. */
    public function testADifferentPhoneCreatesADifferentLead(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $first  = $this->submit($partner, ['phone' => '6912345678', 'consent' => true]);
        $second = $this->submit($partner, ['phone' => '6987654321', 'consent' => true]);

        self::assertNotSame($first->get_data()['ref'], $second->get_data()['ref']);
    }

    /**
     * Υποψήφιος που ήδη μετατράπηκε σε σύμβαση δεν "παίρνει πίσω" νέα στοιχεία
     * σιωπηλά -- η ιδεμποτεντία κοιτάζει ρητά `contract_id IS NULL`.
     */
    public function testAnAlreadyConvertedLeadDoesNotAbsorbANewSubmission(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $first = $this->submit($partner, ['phone' => '6912345678', 'consent' => true]);

        // Πρέπει να είναι ΠΡΑΓΜΑΤΙΚΗ σύμβαση: το leads.contract_id έχει FK
        // (SET NULL on delete) προς contracts.id -- ένα ψεύτικο id σαν 999999
        // απορρίπτεται σιωπηλά από τη MySQL (το IntegrationTestCase κάνει
        // hide_errors), οπότε το UPDATE δεν πιάνει και το test δείχνει
        // ψευδώς πράσινο πρόβλημα. Βλ. AddForeignKeys.php.
        $contractId = (new \EnergyCRM\Persistence\ContractRepository())->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            \EnergyCRM\Access\UserScope::forSelf($partner)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        global $wpdb;
        $wpdb->update(
            \EnergyCRM\Persistence\Tables::name(\EnergyCRM\Persistence\Tables::LEADS),
            ['contract_id' => $contractId],
            ['partner_user_id' => $partner, 'phone' => '306912345678']
        );

        $second = $this->submit($partner, ['phone' => '6912345678', 'consent' => true]);

        self::assertNotSame(
            $first->get_data()['ref'],
            $second->get_data()['ref'],
            'Υποβολή πάνω σε ήδη-μετατρεμμένο υποψήφιο επέστρεψε το ίδιο ref.'
        );
    }

    /** @param array<string, mixed> $body */
    private function submit(int $partner, array $body): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/intake/' . ECRM_Intake::token($partner));
        $request->set_body_params($body);

        return rest_do_request($request);
    }

    /** @return array<string, mixed>|null */
    private function findLead(int $partner): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE partner_user_id = %d ORDER BY id DESC LIMIT 1',
                \EnergyCRM\Persistence\Tables::name(\EnergyCRM\Persistence\Tables::LEADS),
                $partner
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }
}
