<?php

/**
 * Η μετακόμιση των παλαιών εγγράφων από τη media library, δοκιμασμένη απευθείας.
 *
 * Τα πέντε tests ήρθαν αυτούσια από το `FileRepositoryTest` όταν η ομάδα βγήκε
 * σε δική της κλάση (2026-08-15). **Καμία assertion δεν άλλαξε** — μόνο ποιον
 * καλούν: `$this->files->unprotectedCount()` έγινε `$this->documents->count()`
 * και `$this->files->protectBatch()` έγινε `$this->documents->protectBatch()`.
 * Αυτό ήταν και το κριτήριο επιτυχίας ολόκληρου του σπασίματος.
 *
 * *Τα fixtures είναι αντιγραμμένα από το αρχείο προέλευσης αντί να βγουν σε
 * trait. Σκόπιμα: είναι έξι μικρές μέθοδοι που στήνουν γραμμές και bytes, και
 * ένα κοινό trait θα έδενε δύο σουίτες που από εδώ και πέρα αλλάζουν για
 * διαφορετικούς λόγους — η μία ακολουθεί τη διαγραφή, η άλλη μια υπηρεσία με
 * ημερομηνία λήξης.*
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\UnprotectedDocuments;
use EnergyCRM\Services;

final class UnprotectedDocumentsTest extends IntegrationTestCase
{
    private UnprotectedDocuments $documents;

    private int $providerId;

    /** @var list<string> */
    private array $filesBefore = [];

    /** @var list<int> */
    private array $attachmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Η ίδια υπηρεσία που καλωδιώνει το plugin στο boot: ίδιος πίνακας,
        // ίδιος προστατευμένος φάκελος με κάθε πραγματικό καλούντα.
        $this->documents = Services::unprotectedDocuments();

        $this->filesBefore = $this->storageContents();
        $this->providerId  = $this->makeProvider();
    }

    protected function tearDown(): void
    {
        foreach (array_diff($this->storageContents(), $this->filesBefore) as $path) {
            wp_delete_file($path);
        }

        // Best effort: most tests clean their own attachment up by calling the
        // method under test, but a failed assertion partway through must not
        // leave one behind for the next test to trip over.
        foreach ($this->attachmentIds as $attachmentId) {
            if (get_post($attachmentId) !== null) {
                wp_delete_attachment($attachmentId, true);
            }
        }

        parent::tearDown();
    }

    // --- count() -------------------------------------------------------------

    /**
     * count()'s WHERE clause is `protected = 0 OR protected IS NULL`, but
     * EnsureLegacyColumns defines the column `TINYINT NOT NULL DEFAULT 0` — on
     * this schema no row can ever hold NULL, so that half of the condition
     * cannot be exercised here. Confirmed the hard way: this test originally
     * inserted a NULL row and asserted a count of 2; MySQL silently dropped
     * that insert under the NOT NULL constraint and the count came back 1.
     * Left as a schema fact rather than a code change — the OR-NULL branch is
     * presumably defensive for an older install where the column allowed it,
     * and this test covers the state the current schema can actually produce.
     */
    public function testCountCountsZeroButNotOne(): void
    {
        global $wpdb;

        $contractId = $this->makeContract();

        foreach ([0, 0, 1] as $protected) {
            $wpdb->insert(Tables::name(Tables::FILES), [
                'contract_id' => $contractId,
                'doc_kind'    => 'id_card',
                'filename'    => 'x.jpg',
                'mime'        => 'image/jpeg',
                'path'        => $this->putBytes(),
                'protected'   => $protected,
            ]);
        }

        self::assertSame(2, $this->documents->count());
    }

    // --- protectBatch() ------------------------------------------------------

    public function testProtectBatchFlagsARowAlreadyInsideStorageWithoutCopying(): void
    {
        global $wpdb;

        $contractId = $this->makeContract();
        $path       = $this->putBytes();

        $wpdb->insert(Tables::name(Tables::FILES), [
            'contract_id' => $contractId,
            'doc_kind'    => 'id_card',
            'filename'    => 'x.jpg',
            'mime'        => 'image/jpeg',
            'path'        => $path,
            'protected'   => 0,
        ]);
        $fileId = (int) $wpdb->insert_id;

        $report = $this->documents->protectBatch(10);

        self::assertSame(['protected' => 1, 'missing' => 0, 'failed' => 0, 'skipped' => 0], $report);

        $row = $this->fileRow($fileId);
        self::assertNotNull($row);
        self::assertSame('1', (string) $row['protected']);
        self::assertSame($path, $row['path']);
    }

    /** The realistic case: a legacy document still sitting in the media library. */
    public function testProtectBatchCopiesAMediaLibraryFileIntoStorageAndDeletesTheOriginal(): void
    {
        global $wpdb;

        $contractId   = $this->makeContract();
        $attachmentId = $this->makeMediaLibraryAttachment();
        $sourcePath   = (string) get_attached_file($attachmentId);

        self::assertFileExists($sourcePath, 'The fixture did not actually write a file.');

        $wpdb->insert(Tables::name(Tables::FILES), [
            'contract_id'   => $contractId,
            'attachment_id' => $attachmentId,
            'doc_kind'      => 'id_card',
            'filename'      => 'legacy.jpg',
            'mime'          => 'image/jpeg',
            'path'          => '',
            'protected'     => 0,
        ]);
        $fileId = (int) $wpdb->insert_id;

        $report = $this->documents->protectBatch(10);

        self::assertSame(['protected' => 1, 'missing' => 0, 'failed' => 0, 'skipped' => 0], $report);

        $row = $this->fileRow($fileId);
        self::assertNotNull($row);
        self::assertSame('1', (string) $row['protected']);
        self::assertNotSame('', (string) $row['path']);
        self::assertFileExists((string) $row['path']);
        self::assertStringStartsWith(rtrim(ECRM_Files::dir(), '/\\'), (string) $row['path']);
        self::assertNull($row['attachment_id']);

        self::assertNull(get_post($attachmentId), 'protectBatch() must delete the attachment once its copy is safe.');
        self::assertFileDoesNotExist(
            $sourcePath,
            'wp_delete_attachment(..., true) removes the underlying file along with the post.'
        );
    }

    public function testProtectBatchReportsAMissingSourceWithoutTouchingTheRow(): void
    {
        global $wpdb;

        $contractId = $this->makeContract();

        $wpdb->insert(Tables::name(Tables::FILES), [
            'contract_id'   => $contractId,
            'attachment_id' => null,
            'doc_kind'      => 'id_card',
            'filename'      => 'gone.jpg',
            'mime'          => 'image/jpeg',
            'path'          => '',
            'protected'     => 0,
        ]);
        $fileId = (int) $wpdb->insert_id;

        $report = $this->documents->protectBatch(10);

        self::assertSame(['protected' => 0, 'missing' => 1, 'failed' => 0, 'skipped' => 0], $report);

        $row = $this->fileRow($fileId);
        self::assertNotNull($row);
        self::assertSame('0', (string) $row['protected']);
    }

    /** Bounded because it runs on cron — the limit is the whole point of that. */
    public function testProtectBatchHonoursTheLimit(): void
    {
        $contractId = $this->makeContract();

        for ($i = 0; $i < 3; $i++) {
            $this->makeUnprotectedRow($contractId);
        }

        $report = $this->documents->protectBatch(2);

        self::assertSame(2, $report['protected']);
        self::assertSame(1, $this->documents->count());
    }

    // --- Fixtures ------------------------------------------------------------

    /** One provider for the whole test, reused by every contract. */
    private function makeProvider(): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug' => 'ecrm-unprot-test-' . wp_generate_password(8, false),
            'name' => 'Δοκιμαστικός Πάροχος',
        ]);

        $providerId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $providerId, 'The provider fixture was not inserted.');

        return $providerId;
    }

    private function makeContract(): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'customer_id'     => (new CustomerRepository())->create($this->customerData()),
            'partner_user_id' => $this->makePartner(),
            'provider_id'     => $this->providerId,
            'status'          => 'new',
            'energy_type'     => 'power',
            'code'            => 'ECRM-UD-' . wp_generate_password(6, false),
        ]);

        $contractId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $contractId, 'The contract fixture was not inserted.');

        return $contractId;
    }

    /** A row already inside protected storage, unflagged — the cheap fixture. */
    private function makeUnprotectedRow(int $contractId): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::FILES), [
            'contract_id' => $contractId,
            'doc_kind'    => 'id_card',
            'filename'    => 'x.jpg',
            'mime'        => 'image/jpeg',
            'path'        => $this->putBytes(),
            'protected'   => 0,
        ]);

        return (int) $wpdb->insert_id;
    }

    /** A legacy document the way it actually arrives: in the media library. */
    private function makeMediaLibraryAttachment(): int
    {
        $bits = wp_upload_bits('legacy-' . wp_generate_password(8, false) . '.jpg', null, 'legacy id card bytes');

        if ($bits['error'] !== false) {
            self::fail('wp_upload_bits() failed: ' . (string) $bits['error']);
        }

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => 'image/jpeg',
            'post_title'     => 'ecrm-unprot-test-legacy',
            'post_status'    => 'inherit',
        ], $bits['file']);

        self::assertIsInt($attachmentId, 'Could not create the attachment fixture.');
        self::assertGreaterThan(0, $attachmentId);

        update_attached_file($attachmentId, $bits['file']);

        $this->attachmentIds[] = $attachmentId;

        return $attachmentId;
    }

    /** Bytes on disk with no row pointing at them. */
    private function putBytes(): string
    {
        $saved = ECRM_Files::put_bytes('fixture bytes ' . wp_generate_password(8, false), 'jpg', 'image/jpeg', 'x.jpg');

        self::assertIsArray($saved, 'Fixture failed to write bytes to protected storage.');

        return (string) $saved['path'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fileRow(int $id): ?array
    {
        global $wpdb;

        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE id = %d', Tables::name(Tables::FILES), $id),
            ARRAY_A
        );

        return $row;
    }

    /**
     * @return list<string>
     */
    private function storageContents(): array
    {
        $found = glob(rtrim(ECRM_Files::dir(), '/\\') . DIRECTORY_SEPARATOR . '*');

        return $found === false ? [] : array_values(array_filter($found, 'is_file'));
    }
}
