<?php

/**
 * `POST /track/{token}/upload` -- ο δηλωμένος τύπος δεν αρκεί, τα bytes αποφασίζουν.
 *
 * AUDIT εύρημα, 29/08: για `image/webp` και `image/heic` ο έλεγχος magic-byte
 * ήταν σκόπιμα παραλειμμένος ("trust the whitelist + size check") -- ο μόνος
 * τύπος αρχείου που ο πελάτης δηλώνει ελεύθερα χωρίς κανένας να τσεκάρει τα
 * ίδια τα bytes. Η αντίστοιχη συνδεδεμένη διαδρομή (`ECRM_Files::store()`,
 * μέσω `UploadCheck::sniff()`) το κάνει σωστά για όλους τους τύπους από τις
 * 2026-08-18. Αυτό το αρχείο αποδεικνύει ότι το κενό έκλεισε: ο δηλωμένος
 * τύπος πρέπει πλέον να ταιριάζει με αυτό που λένε τα ίδια τα bytes, και για
 * webp/heic όπως ήδη ίσχυε για pdf/png/jpeg.
 *
 * `ECRM_Intake::rest_file()` μοιράζεται την ίδια αλλαγή, λέξη προς λέξη --
 * IntakeFileTest το δηλώνει ρητά στο δικό του docblock ("δεν ξαναδοκιμάζεται
 * εδώ η μηχανή"), οπότε αυτό το αρχείο ΕΙΝΑΙ εκείνη η δοκιμή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Tracking;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use WP_REST_Request;
use WP_REST_Response;

final class TrackingUploadMagicBytesTest extends IntegrationTestCase
{
    /** 1x1 PNG, valid, 67 bytes -- above the 64-byte floor. */
    private const VALID_PNG =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4'
        . 'nGNgAAAAAgABSK+kcQAAAABJRU5ErkJggg==';

    private ContractRepository $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
    }

    /** The declared type must still match its own bytes -- unchanged behaviour. */
    public function testAGenuinePngIsAccepted(): void
    {
        $response = $this->upload('image/png', self::VALID_PNG);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    /**
     * The exact shape of the finding: PNG bytes wearing a `image/webp` label.
     * Before the fix this was accepted -- webp trusted the label outright.
     */
    public function testPngBytesDeclaredAsWebpAreRejected(): void
    {
        $response = $this->upload('image/webp', self::VALID_PNG);

        self::assertSame(400, $response->get_status());
        self::assertSame('Ο τύπος αρχείου δεν ταιριάζει.', $response->get_data()['error'] ?? null);
    }

    /** Same finding, the other newly-unchecked type. */
    public function testPngBytesDeclaredAsHeicAreRejected(): void
    {
        $response = $this->upload('image/heic', self::VALID_PNG);

        self::assertSame(400, $response->get_status());
    }

    /**
     * The control: a GENUINE webp must still go through. Without this, a fix
     * that rejected every webp unconditionally would pass the two tests above
     * for the wrong reason.
     */
    public function testAGenuineWebpIsAccepted(): void
    {
        $bytes = 'RIFF' . "\x24\x00\x00\x00" . 'WEBPVP8 ' . str_repeat("\x00", 48);

        $response = $this->upload('image/webp', base64_encode($bytes));

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    /** Same control for heic. */
    public function testAGenuineHeicIsAccepted(): void
    {
        $bytes = "\x00\x00\x00\x18" . 'ftyp' . 'heic' . str_repeat("\x00", 52);

        $response = $this->upload('image/heic', base64_encode($bytes));

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
    }

    private function upload(string $declaredMime, string $base64): WP_REST_Response
    {
        $contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->makePartner())
        );

        self::assertGreaterThan(0, $contractId, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');

        $request = new WP_REST_Request('POST', '/ecrm/v1/track/' . ECRM_Tracking::token($contractId) . '/upload');
        $request->set_body_params([
            'kind'     => 'other',
            'filename' => 'file.bin',
            'data'     => 'data:' . $declaredMime . ';base64,' . $base64,
        ]);

        return rest_do_request($request);
    }
}
