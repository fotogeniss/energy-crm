<?php

/**
 * `POST /intake/{token}/file` -- ένα έγγραφο τη φορά, από τη δημόσια σελίδα.
 *
 * Οι έλεγχοι είναι αυτούσιοι από το `ECRM_Tracking::rest_upload()` (whitelist
 * MIME, magic bytes, όριο μεγέθους, όριο πλήθους) -- ο σκοπός εδώ δεν είναι να
 * ξαναδοκιμαστεί εκείνη η μηχανή, είναι να δοκιμαστεί η καλωδίωση γύρω της
 * που είναι δικός του κώδικας: το ref πρέπει να αντιστοιχεί σε lead του ΙΔΙΟΥ
 * πωλητή, ανεπηρέαστο, χωρίς σύμβαση ακόμα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Intake;
use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;

final class IntakeFileTest extends IntegrationTestCase
{
    /** 1x1 PNG έγκυρο, 67 bytes -- πάνω από το ελάχιστο των 64. */
    private const VALID_PNG =
        'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4'
        . 'nGNgAAAAAgABSK+kcQAAAABJRU5ErkJggg==';

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Η βασική διαδρομή: αρχείο κρέμεται στον σωστό υποψήφιο. */
    public function testAValidUploadIsStored(): void
    {
        [$partner, $ref, $leadId] = $this->partnerWithLead();

        $response = $this->upload($partner, $ref, self::VALID_PNG, 'id_card');

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        $file = $this->fileFor($leadId);
        self::assertNotNull($file, 'Δεν γράφτηκε γραμμή στο files.');
        self::assertSame('id_card', $file['doc_kind']);
        self::assertSame('image/png', $file['mime']);
        self::assertSame('1', $file['protected']);
        self::assertNull($file['contract_id'], 'Το αρχείο δεν πρέπει να έχει ακόμα σύμβαση.');
    }

    /** Είδος εγγράφου εκτός της κλειστής λίστας πέφτει σε "other", δεν απορρίπτεται. */
    public function testAnUnknownKindFallsBackToOther(): void
    {
        [$partner, $ref, $leadId] = $this->partnerWithLead();

        $response = $this->upload($partner, $ref, self::VALID_PNG, 'authorization-form');

        self::assertSame(200, $response->get_status());
        self::assertSame('other', $this->fileFor($leadId)['doc_kind'] ?? null);
    }

    /** Token άκυρο -- δεν φτάνει καν στο ref. */
    public function testAnInvalidTokenIsRefused(): void
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/intake/1-' . str_repeat('0', 20) . '/file');
        $request->set_body_params([
            'ref'  => '1-' . str_repeat('0', 20),
            'kind' => 'id_card',
            'data' => self::VALID_PNG,
        ]);

        self::assertSame(404, rest_do_request($request)->get_status());
    }

    /** Ref που δεν επαληθεύεται καθόλου. */
    public function testAGarbledRefIsRefused(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $response = $this->upload($partner, 'not-a-real-ref', self::VALID_PNG, 'id_card');

        self::assertSame(404, $response->get_status());
    }

    /**
     * Το ref είναι έγκυρη υπογραφή, αλλά η γραμμή leads λέει άλλον πωλητή --
     * ο δεύτερος, ρητός έλεγχος ιδιοκτησίας πέρα από την υπογραφή.
     */
    public function testALeadReassignedToAnotherPartnerIsRefused(): void
    {
        [$partner, $ref, $leadId] = $this->partnerWithLead();
        $other                    = $this->makeCrmUser(Roles::SELLER);

        global $wpdb;
        $wpdb->update(Tables::name(Tables::LEADS), ['partner_user_id' => $other], ['id' => $leadId]);

        $response = $this->upload($partner, $ref, self::VALID_PNG, 'id_card');

        self::assertSame(404, $response->get_status());
    }

    /**
     * Υποψήφιος που ήδη μετατράπηκε σε σύμβαση δεν δέχεται άλλα αρχεία εδώ --
     * περνούν πλέον από το /track, όπου ανήκουν.
     */
    public function testAnAlreadyConvertedLeadRefusesMoreFiles(): void
    {
        [$partner, $ref, $leadId] = $this->partnerWithLead();

        // Πρέπει να είναι ΠΡΑΓΜΑΤΙΚΗ σύμβαση: το leads.contract_id έχει FK
        // (SET NULL on delete) προς contracts.id -- ένα ψεύτικο id σαν 999999
        // απορρίπτεται σιωπηλά από τη MySQL (το IntegrationTestCase κάνει
        // hide_errors), οπότε το UPDATE δεν πιάνει. Βλ. AddForeignKeys.php.
        $contractId = (new \EnergyCRM\Persistence\ContractRepository())->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            \EnergyCRM\Access\UserScope::forSelf($partner)
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        global $wpdb;
        $wpdb->update(Tables::name(Tables::LEADS), ['contract_id' => $contractId], ['id' => $leadId]);

        $response = $this->upload($partner, $ref, self::VALID_PNG, 'id_card');

        self::assertSame(404, $response->get_status());
    }

    /** Έξι αρχεία είναι το όριο -- το έβδομο δεν γίνεται δωρεάν αποθηκευτικός χώρος. */
    public function testTheSeventhFileIsRefused(): void
    {
        [$partner, $ref, $leadId] = $this->partnerWithLead();

        global $wpdb;
        for ($i = 0; $i < 6; $i++) {
            $wpdb->insert(Tables::name(Tables::FILES), [
                'lead_id'   => $leadId,
                'doc_kind'  => 'id_card',
                'filename'  => "existing-{$i}.png",
                'mime'      => 'image/png',
                'path'      => "existing-{$i}.png",
                'protected' => 1,
            ]);
        }

        $response = $this->upload($partner, $ref, self::VALID_PNG, 'id_card');

        self::assertSame(400, $response->get_status());
    }

    /** Δηλωμένος τύπος PNG, περιεχόμενο που δεν αρχίζει με την υπογραφή PNG. */
    public function testDeclaredMimeMismatchingTheBytesIsRefused(): void
    {
        [$partner, $ref, $leadId] = $this->partnerWithLead();

        $fake = 'data:image/png;base64,' . base64_encode(str_repeat('not a png at all, just filler', 4));

        $response = $this->upload($partner, $ref, $fake, 'id_card');

        self::assertSame(400, $response->get_status());
        self::assertNull($this->fileFor($leadId));
    }

    /** Τύπος εκτός της επιτρεπτής λίστας (JPG/PNG/WEBP/HEIC/PDF). */
    public function testADisallowedMimeIsRefused(): void
    {
        [$partner, $ref, $leadId] = $this->partnerWithLead();

        $text = 'data:text/plain;base64,' . base64_encode(str_repeat('plain text file', 5));

        $response = $this->upload($partner, $ref, $text, 'id_card');

        self::assertSame(400, $response->get_status());
        self::assertNull($this->fileFor($leadId));
    }

    /** @return array{0: int, 1: string, 2: int} πωλητής, ref, lead id */
    private function partnerWithLead(): array
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $request = new WP_REST_Request('POST', '/ecrm/v1/intake/' . ECRM_Intake::token($partner));
        $request->set_body_params(['phone' => '6912345678', 'consent' => true]);
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status(), 'Το fixture υποψηφίου απέτυχε να φτιαχτεί.');

        $ref = (string) $response->get_data()['ref'];

        global $wpdb;
        $leadId = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE partner_user_id = %d ORDER BY id DESC LIMIT 1',
            Tables::name(Tables::LEADS),
            $partner
        ));

        return [$partner, $ref, $leadId];
    }

    private function upload(int $partner, string $ref, string $data, string $kind): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/intake/' . ECRM_Intake::token($partner) . '/file');
        $request->set_body_params(['ref' => $ref, 'kind' => $kind, 'data' => $data]);

        return rest_do_request($request);
    }

    /** @return array<string, mixed>|null */
    private function fileFor(int $leadId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE lead_id = %d ORDER BY id DESC LIMIT 1',
                Tables::name(Tables::FILES),
                $leadId
            ),
            ARRAY_A
        );

        return $row === null ? null : $row;
    }
}
