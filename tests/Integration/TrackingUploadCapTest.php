<?php

/**
 * `POST /track/{token}/upload` -- the file-count cap, mirrored from intake.
 *
 * (440) Από 21/11: 10 αρχεία, και μετρούν μόνο όσα ανέβασε ο πελάτης από τον
 * σύνδεσμο (απόφαση ιδιοκτήτη).
 *
 * AUDIT finding, 29/08: ECRM_Intake::rest_file() has always capped a lead at
 * six files, with the reason written on the check itself -- "without a cap,
 * the link becomes free storage for whoever holds it." ECRM_Tracking::rest_upload()
 * is the other unauthenticated write path and never got the same cap, even
 * though anyone holding a tracking link -- the customer, or anyone they
 * forwarded it to -- can reach it. IntakeFileTest::testTheSeventhFileIsRefused
 * already proves the intake side; this is the same proof for tracking.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Tracking;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class TrackingUploadCapTest extends IntegrationTestCase
{
    /** 1x1 PNG, valid, 67 bytes -- above the 64-byte floor. */
    private const VALID_PNG =
        'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4'
        . 'nGNgAAAAAgABSK+kcQAAAABJRU5ErkJggg==';

    private ContractRepository $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
    }

    /** (440) Το δέκατο αρχείο του πελάτη περνά. */
    public function testTheTenthCustomerFileIsAccepted(): void
    {
        $contractId = $this->contract();
        $this->seedFiles($contractId, 9, ECRM_Tracking::VIA_LINK);

        $response = $this->upload($contractId, self::VALID_PNG);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    /** (440) Το ενδέκατο όχι. */
    public function testTheEleventhCustomerFileIsRefused(): void
    {
        $contractId = $this->contract();
        $this->seedFiles($contractId, 10, ECRM_Tracking::VIA_LINK);

        $response = $this->upload($contractId, self::VALID_PNG);

        self::assertSame(400, $response->get_status());
        self::assertSame(10, $this->fileCount($contractId), 'A refused upload must not have written a row.');
    }

    /**
     * (440) Τα αρχεία του γραφείου και η υπογραφή δεν μετρούν: ο πελάτης
     * έβλεπε «μέγιστο αριθμό» χωρίς να έχει ανεβάσει τίποτα.
     */
    public function testOfficeFilesDoNotCountAgainstTheCustomer(): void
    {
        $contractId = $this->contract();
        $this->seedFiles($contractId, 12, null);

        $response = $this->upload($contractId, self::VALID_PNG);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    /** Και ό,τι ανέβηκε από εδώ σημειώνεται, αλλιώς το όριο δεν θα έπιανε ποτέ. */
    public function testACustomerUploadIsMarked(): void
    {
        global $wpdb;

        $contractId = $this->contract();
        $this->upload($contractId, self::VALID_PNG);

        self::assertSame(ECRM_Tracking::VIA_LINK, (string) $wpdb->get_var($wpdb->prepare(
            'SELECT uploaded_via FROM %i WHERE contract_id = %d',
            Tables::name(Tables::FILES),
            $contractId
        )));
    }

    /** Files hanging off a DIFFERENT contract do not count against this one's cap. */
    public function testTheCapIsPerContractNotGlobal(): void
    {
        $mine   = $this->contract();
        $theirs = $this->contract();
        $this->seedFiles($theirs, 10, ECRM_Tracking::VIA_LINK);

        $response = $this->upload($mine, self::VALID_PNG);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    /** (440) Η λίστα μετά το ανέβασμα είναι η ίδια με της σελίδας (αέριο). */
    public function testTheListAfterUploadMatchesThePage(): void
    {
        $contractId = $this->contract('gas');

        $page   = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/track/' . ECRM_Tracking::token($contractId)));
        $upload = $this->upload($contractId, self::VALID_PNG);

        self::assertSame(200, $upload->get_status(), (string) ($upload->get_data()['error'] ?? ''));
        self::assertSame(
            array_column($page->get_data()['docs']['items'], 'label'),
            array_column($upload->get_data()['docs']['items'], 'label')
        );
    }

    private function contract(string $energy = 'power'): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => $energy],
            UserScope::forSelf($this->makePartner())
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }

    private function seedFiles(int $contractId, int $howMany, ?string $via): void
    {
        global $wpdb;

        for ($i = 0; $i < $howMany; $i++) {
            $wpdb->insert(Tables::name(Tables::FILES), [
                'contract_id' => $contractId,
                'doc_kind'    => 'other',
                'filename'    => "existing-{$i}.png",
                'mime'        => 'image/png',
                'path'        => "existing-{$i}.png",
                'protected'   => 1,
                'uploaded_via' => $via,
            ]);
        }
    }

    private function fileCount(int $contractId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE contract_id = %d',
            Tables::name(Tables::FILES),
            $contractId
        ));
    }

    private function upload(int $contractId, string $data): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/track/' . ECRM_Tracking::token($contractId) . '/upload');
        $request->set_body_params(['kind' => 'other', 'filename' => 'file.png', 'data' => $data]);

        return rest_do_request($request);
    }
}
