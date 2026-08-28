<?php

/**
 * Ο εξαγωγέας φτάνει στα έγγραφα ΠΡΙΝ υπάρξει αίτηση.
 *
 * ## Γιατί προστέθηκε αυτή η διαδρομή
 *
 * Μέχρι τις 28/08 το `POST /extract` έβρισκε αποθηκευμένα έγγραφα **μόνο**
 * μέσω `contract_id`. Ο «σύνδεσμός μου» όμως αποθηκεύει ό,τι στέλνει ο πελάτης
 * με `lead_id` — οι δύο είναι ξεχωριστές στήλες του ίδιου πίνακα `files`. Το
 * αποτέλεσμα ήταν ότι η ανάγνωση μπορούσε να γίνει μόνο **μετά** τη δημιουργία
 * της σύμβασης, οπότε ο πωλητής έλεγε «ναι, φτιάξ' την» χωρίς να ξέρει τι
 * περιείχαν τα χαρτιά. Δες `docs/UI-INTAKE-HANDOFF.html`.
 *
 * ## Τι δοκιμάζεται εδώ, και τι ΔΕΝ γίνεται να δοκιμαστεί
 *
 * Το `ECRM_Extractor::extract()` χτυπά πραγματικό μοντέλο με χρέωση — δεν
 * καλείται από καμία δοκιμή. Ό,τι υπάρχει **πριν** από αυτή τη γραμμή είναι
 * όμως ακριβώς το κομμάτι που γράφτηκε τώρα και το κομμάτι που μπορεί να
 * διαρρεύσει δεδομένα: η επιλογή πηγής, ο έλεγχος εμβέλειας και η εύρεση των
 * αρχείων. Και τα τρία δοκιμάζονται εδώ, χωρίς δίκτυο.
 *
 * Τα fixtures περνούν από την **πραγματική** δημόσια διαδρομή intake, όχι από
 * χειροκίνητα `INSERT`: η `extractableForLead()` απαιτεί τα bytes να υπάρχουν
 * όντως στον δίσκο (`DocumentStorage::contains()` + `is_readable()`), που
 * είναι ο έλεγχος ασφαλείας, όχι λεπτομέρεια. Μια ψεύτικη γραμμή στο `files`
 * θα περνούσε το test και θα άφηνε τον πραγματικό έλεγχο αδοκίμαστο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Intake;
use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class ExtractionFromLeadTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/extract';

    /** 1x1 PNG έγκυρο, πάνω από το ελάχιστο των 64 bytes -- ίδιο με το IntakeFileTest. */
    private const VALID_PNG =
        'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4'
        . 'nGNgAAAAAgABSK+kcQAAAABJRU5ErkJggg==';

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    // --- η εύρεση των αρχείων ------------------------------------------------

    /**
     * Η βασική διαδρομή: έγγραφο σταλμένο από τον πελάτη βρίσκεται από το lead.
     *
     * Δοκιμάζεται στο repository και όχι μέσω REST, γιατί το REST θα συνέχιζε
     * στον εξαγωγέα -- και το ζητούμενο εδώ είναι ακριβώς το βήμα πριν από
     * αυτόν.
     */
    public function testADocumentSentByTheCustomerIsFoundThroughTheLead(): void
    {
        [$partner, $ref, $leadId] = $this->partnerWithLead();

        $this->upload($partner, $ref, 'id_card');

        $documents = $this->repository()->extractableForLead(
            $leadId,
            ['id_card', 'provider_bill'],
            ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf']
        );

        self::assertCount(1, $documents, 'Το έγγραφο του lead δεν βρέθηκε.');
        self::assertSame('id_card', $documents[0]['kind']);
        self::assertSame('image/png', $documents[0]['mime']);
        self::assertNotSame('', $documents[0]['path']);
    }

    /**
     * Είδος εκτός της λίστας του εξαγωγέα δεν επιστρέφεται.
     *
     * Το intake δέχεται και `other` (ό,τι δεν αναγνωρίζει), αλλά το prompt του
     * μοντέλου είναι γραμμένο για ταυτότητα και λογαριασμό. Ένα τρίτο έγγραφο
     * είναι μόνο κόστος.
     */
    public function testADocumentOfAnUnextractableKindIsIgnored(): void
    {
        [$partner, $ref, $leadId] = $this->partnerWithLead();

        $this->upload($partner, $ref, 'authorization-form');

        self::assertSame(
            [],
            $this->repository()->extractableForLead(
                $leadId,
                ['id_card', 'provider_bill'],
                ['image/png']
            )
        );
    }

    /** Τα έγγραφα ΑΛΛΟΥ lead δεν διαρρέουν σε αυτό. */
    public function testDocumentsOfAnotherLeadAreNotReturned(): void
    {
        [$partnerA, $refA]        = $this->partnerWithLead();
        [$partnerB, $refB, $leadB] = $this->partnerWithLead();

        $this->upload($partnerA, $refA, 'id_card');

        self::assertSame(
            [],
            $this->repository()->extractableForLead(
                $leadB,
                ['id_card', 'provider_bill'],
                ['image/png']
            ),
            'Έγγραφο άλλου υποψηφίου εμφανίστηκε εδώ.'
        );
    }

    // --- η επιλογή πηγής, στο ίδιο το endpoint -------------------------------

    /**
     * Δύο αναγνωριστικά μαζί απορρίπτονται αντί να διαλεγεί το ένα σιωπηλά.
     *
     * Μια σιωπηλή προτεραιότητα εδώ θα ήταν κανόνας που κανείς δεν θυμάται
     * όταν σπάσει -- και θα διάβαζε έγγραφα διαφορετικά από αυτά που νόμιζε
     * ότι ζήτησε ο καλών.
     */
    public function testSendingBothIdentifiersIsRefused(): void
    {
        [$partner, $ref, $leadId] = $this->partnerWithLead();
        $this->upload($partner, $ref, 'id_card');

        wp_set_current_user($partner);

        $response = $this->extract(['lead_id' => $leadId, 'contract_id' => 1]);

        self::assertSame(400, $response->get_status());
    }

    /** Χωρίς αρχεία και χωρίς αναγνωριστικό δεν υπάρχει τίποτα να διαβαστεί. */
    public function testSendingNeitherIdentifierNorFilesIsRefused(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        self::assertSame(400, $this->extract([])->get_status());
    }

    /**
     * Lead άλλου πωλητή απαντά όπως και ανύπαρκτο.
     *
     * Ο έλεγχος είναι ΤΟ σημείο αυτής της αλλαγής: το `lead_id` έρχεται από
     * τον browser και δεν αποδεικνύει τίποτα. Χωρίς αυτό, οποιοσδήποτε πωλητής
     * θα διάβαζε τις σαρωμένες ταυτότητες που έστειλαν οι πελάτες
     * οποιουδήποτε άλλου.
     */
    public function testALeadOfAnotherPartnerIsRefused(): void
    {
        [$owner, $ref, $leadId] = $this->partnerWithLead();
        $this->upload($owner, $ref, 'id_card');

        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $response = $this->extract(['lead_id' => $leadId]);

        self::assertSame(404, $response->get_status(), 'Διέρρευσαν έγγραφα άλλου πωλητή.');
    }

    /** Lead χωρίς κανένα αναγνώσιμο έγγραφο δεν φτάνει ποτέ στον εξαγωγέα. */
    public function testALeadWithNoDocumentsIsRefusedBeforeTheExtractorRuns(): void
    {
        [$partner, , $leadId] = $this->partnerWithLead();

        wp_set_current_user($partner);

        $response = $this->extract(['lead_id' => $leadId]);

        self::assertSame(404, $response->get_status());
    }

    // --- fixtures ------------------------------------------------------------

    private function repository(): \EnergyCRM\Persistence\FileRepository
    {
        return new \EnergyCRM\Persistence\FileRepository(\ECRM_Files::dir());
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

    /** @return array{0: int, 1: string, 2: int} πωλητής, ref, lead id */
    private function partnerWithLead(): array
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $request = new WP_REST_Request('POST', '/ecrm/v1/intake/' . ECRM_Intake::token($partner));
        $request->set_body_params(['phone' => '6912345678', 'consent' => true]);
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status(), 'Το fixture υποψηφίου απέτυχε να φτιαχτεί.');

        global $wpdb;
        $leadId = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE partner_user_id = %d ORDER BY id DESC LIMIT 1',
            Tables::name(Tables::LEADS),
            $partner
        ));

        self::assertGreaterThan(0, $leadId, 'Ο υποψήφιος δεν γράφτηκε.');

        return [$partner, (string) $response->get_data()['ref'], $leadId];
    }

    private function upload(int $partner, string $ref, string $kind): void
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/intake/' . ECRM_Intake::token($partner) . '/file');
        $request->set_body_params(['ref' => $ref, 'kind' => $kind, 'data' => self::VALID_PNG]);

        $response = rest_do_request($request);

        self::assertSame(
            200,
            $response->get_status(),
            'Το fixture εγγράφου απέτυχε: ' . (string) ($response->get_data()['error'] ?? '')
        );
    }
}
