<?php

/**
 * `POST /track/{token}/upload` -- the file-count cap, mirrored from intake.
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

    /** The sixth file still goes through -- the cap is >=6, not >5. */
    public function testTheSixthFileIsAccepted(): void
    {
        $contractId = $this->contract();
        $this->seedFiles($contractId, 5);

        $response = $this->upload($contractId, self::VALID_PNG);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    /** The seventh is refused -- same threshold, same message shape as intake. */
    public function testTheSeventhFileIsRefused(): void
    {
        $contractId = $this->contract();
        $this->seedFiles($contractId, 6);

        $response = $this->upload($contractId, self::VALID_PNG);

        self::assertSame(400, $response->get_status());
        self::assertSame(6, $this->fileCount($contractId), 'A refused upload must not have written a row.');
    }

    /** Files hanging off a DIFFERENT contract do not count against this one's cap. */
    public function testTheCapIsPerContractNotGlobal(): void
    {
        $mine   = $this->contract();
        $theirs = $this->contract();
        $this->seedFiles($theirs, 6);

        $response = $this->upload($mine, self::VALID_PNG);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    private function contract(): int
    {
        $contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->makePartner())
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        return $contractId;
    }

    private function seedFiles(int $contractId, int $howMany): void
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
