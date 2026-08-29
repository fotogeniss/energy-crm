<?php

/**
 * `POST /contracts/{id}/files` — ανέβασμα δικαιολογητικών (ταυτότητα,
 * λογαριασμός) πάνω σε μία σύμβαση.
 *
 * AUDIT εύρημα 2.5 (EKKREMI-29-08.html): «η συνδεδεμένη διαδρομή έχει
 * λιγότερη κάλυψη από την ανώνυμη» -- μετά το §2.1 οι ανώνυμες διαδρομές
 * (`/track/{token}/upload`, `/intake/...`) πήραν tests για το magic-byte
 * κενό τους. Αυτή, η κύρια διαδρομή που χρησιμοποιεί ο συνδεδεμένος
 * συνεργάτης, δεν είχε ΚΑΝΕΝΑ integration test -- ούτε καν τη γραμμή scope
 * (`$this->contracts->exists($contractId, $scope)`) πριν από οτιδήποτε άλλο.
 *
 * Τι ΔΕΝ καλύπτει αυτό το αρχείο, σκόπιμα: το πραγματικό «αποθηκεύτηκε»
 * μονοπάτι μέσα στο `ECRM_Files::store()` απαιτεί `is_uploaded_file()` να
 * επιστρέψει true, κάτι που η PHP δίνει ΜΟΝΟ σε αρχείο που πράγματι πέρασε
 * από πραγματικό HTTP multipart upload σε αυτό το request -- δεν
 * προσομοιώνεται με κατασκευασμένο `tmp_name` σε PHPUnit, και δεν υπάρχει
 * κανένα σημείο σε αυτό το repo (ούτε στον πυρήνα του WordPress) που να το
 * παρακάμπτει καθαρά. Ό,τι ελέγχεται εδώ είναι η λογική του
 * DocumentsController ΠΡΙΝ φτάσει στο store() -- το scope gate, το άδειο
 * αίτημα, και το όριο MAX_FILES -- που είναι ακριβώς όπου ζει ο κίνδυνος
 * που περιγράφει το audit.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use WP_REST_Request;
use WP_REST_Response;

final class DocumentsUploadScopeTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private int $owner;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts  = new ContractRepository();
        $this->owner      = $this->makeCrmUser(Roles::SELLER);
        $this->contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->owner)
        );

        self::assertGreaterThan(0, $this->contractId);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * The gate the audit found untested: a contract outside the caller's
     * scope must refuse before anything about the upload is even looked at.
     */
    public function testUploadingToAContractOutsideScopeIsRefused(): void
    {
        $stranger = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($stranger);

        $response = $this->upload($this->contractId, [$this->fakeFile('a.png')]);

        self::assertSame(404, $response->get_status());
        self::assertSame('Δεν βρέθηκε η σύμβαση.', $response->get_data()['error']);
    }

    /**
     * Control: the owner must reach the upload pipeline at all (the scope
     * gate must not be a false positive that also blocks the legitimate
     * caller). The route still answers 200 even when every individual file
     * fails further in -- see the class docblock for why a fake tmp_name
     * can't reach a genuine "saved" outcome here.
     */
    public function testTheOwnerReachesThePipelinePastTheScopeGate(): void
    {
        wp_set_current_user($this->owner);

        $response = $this->upload($this->contractId, [$this->fakeFile('a.png')]);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertTrue($data['ok']);
        self::assertCount(
            1,
            $data['rejected'],
            'A fake tmp_name cannot pass is_uploaded_file(), so it must land in rejected, not silently vanish.'
        );
    }

    public function testNoFilesInTheRequestIsRefused(): void
    {
        wp_set_current_user($this->owner);

        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $this->contractId . '/files');
        $request->set_url_params(['id' => $this->contractId]);
        $request->set_file_params(['files' => []]);

        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('Δεν ανέβηκαν αρχεία.', $response->get_data()['error']);
    }

    /**
     * The docblock's own reason for MAX_FILES: without a cap, one request
     * writes to disk/DB once per file before it ever says no. Eleven files
     * must produce exactly one 'too_many' rejection, on the eleventh.
     */
    public function testMoreThanTenFilesCapsTheOverflowWithoutReachingStore(): void
    {
        wp_set_current_user($this->owner);

        $files = array_map(fn (int $i): array => $this->fakeFile("doc{$i}.png"), range(1, 11));

        $response = $this->upload($this->contractId, $files);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertCount(11, $data['rejected']);
        self::assertSame('Δέχεται μέχρι 10 αρχεία τη φορά.', $data['rejected'][10]['reason']);
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    private function fakeFile(string $name): array
    {
        return [
            'name'     => $name,
            'type'     => 'image/png',
            'tmp_name' => '/tmp/not-a-real-upload-' . $name,
            'error'    => UPLOAD_ERR_OK,
            'size'     => 128,
        ];
    }

    /**
     * @param list<array{name: string, type: string, tmp_name: string, error: int, size: int}> $files
     */
    private function upload(int $contractId, array $files): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/ecrm/v1/contracts/' . $contractId . '/files');
        $request->set_url_params(['id' => $contractId]);
        $request->set_file_params([
            'files' => [
                'name'     => array_column($files, 'name'),
                'type'     => array_column($files, 'type'),
                'tmp_name' => array_column($files, 'tmp_name'),
                'error'    => array_column($files, 'error'),
                'size'     => array_column($files, 'size'),
            ],
        ]);

        return rest_do_request($request);
    }
}
