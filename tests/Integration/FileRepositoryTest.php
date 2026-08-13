<?php

/**
 * FileRepository, exercised directly.
 *
 * Grepped for its own method names in tests/ before this file existed and got
 * one hit: a comment. That measurement was honest but incomplete — attach(),
 * latestPathOfKind() and purgeGenerated() are all exercised, with real
 * assertions, through ContractDocuments::store() in ContractDocumentsTest.
 * Nothing here repeats them.
 *
 * What was left after checking behaviour rather than names:
 *
 *   - forContract() — nothing calls it in any test. ContractDocumentsTest reads
 *     the files table with its own SQL instead.
 *   - purgeForContracts() — PersonalDataErasureTest calls it, but that
 *     contract never has a file row, so the call always short-circuits on an
 *     empty result set. The row-and-bytes deletion it exists for has never run
 *     under test.
 *   - purgeOrphans(), unprotectedCount(), protectBatch() — no test touches any
 *     of the three.
 *
 * This file covers those five, plus one thing found while writing it rather
 * than fixed: see testReplaceKindOrphansTheBytesOfTheRowItDeletes().
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\FileRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class FileRepositoryTest extends IntegrationTestCase
{
    private FileRepository $files;

    private int $providerId;

    /** @var list<string> */
    private array $filesBefore = [];

    /** @var list<int> */
    private array $attachmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The instance the plugin wired up at boot: same table names and the
        // same protected directory every real caller uses.
        $this->files = Services::files();

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

    // --- forContract() -------------------------------------------------------

    public function testForContractReturnsEveryDocumentOrderedByInsertion(): void
    {
        $contractId = $this->makeContract();

        $first  = $this->files->attach($contractId, 'id_card', 'id.jpg', 'image/jpeg', $this->putBytes());
        $second = $this->files->attach($contractId, 'signature', 'sig.png', 'image/png', $this->putBytes());

        $documents = $this->files->forContract($contractId);

        self::assertSame([$first, $second], array_map('intval', array_column($documents, 'id')));
        self::assertSame('id_card', $documents[0]['doc_kind']);
        self::assertSame('signature', $documents[1]['doc_kind']);
    }

    /** The WHERE clause is the whole method — worth proving it is really there. */
    public function testForContractDoesNotLeakAnotherContractsDocuments(): void
    {
        $mine   = $this->makeContract();
        $theirs = $this->makeContract();

        $this->files->attach($theirs, 'id_card', 'id.jpg', 'image/jpeg', $this->putBytes());

        self::assertSame([], $this->files->forContract($mine));
    }

    public function testForContractOfAnInvalidIdIsEmptyWithoutQuerying(): void
    {
        self::assertSame([], $this->files->forContract(0));
        self::assertSame([], $this->files->forContract(-5));
    }

    // --- replaceKind() ---------------------------------------------------------

    public function testReplaceKindLeavesExactlyOneRowOfThatKind(): void
    {
        $contractId = $this->makeContract();

        $this->files->attach($contractId, 'signature', 'first.png', 'image/png', $this->putBytes());
        $newId = $this->files->replaceKind($contractId, 'signature', 'second.png', 'image/png', $this->putBytes());

        $documents = $this->files->forContract($contractId);

        self::assertCount(1, $documents);
        self::assertSame($newId, (int) $documents[0]['id']);
        self::assertSame('second.png', $documents[0]['filename']);
    }

    public function testReplaceKindDoesNotTouchOtherKinds(): void
    {
        $contractId = $this->makeContract();

        $this->files->attach($contractId, 'id_card', 'id.jpg', 'image/jpeg', $this->putBytes());
        $this->files->replaceKind($contractId, 'signature', 'sig.png', 'image/png', $this->putBytes());

        self::assertSame(
            ['id_card', 'signature'],
            array_column($this->files->forContract($contractId), 'doc_kind')
        );
    }

    /**
     * Found while writing this net, not fixed here.
     *
     * Every other removal path in this class — purgeGenerated(),
     * purgeForContracts(), purgeOrphans() — calls deleteBytes() before it
     * deletes rows. replaceKind() does not: it deletes the old row and inserts
     * a new one, and the bytes the old row pointed at are never unlinked. That
     * is exactly "a caller removing half" — the failure mode the class header
     * says this class exists to prevent.
     *
     * SigningController::storeSignatureImage() calls replaceKind() on every
     * re-sign, so a customer who signs twice leaves the first drawing on disk,
     * referenced by nothing. purgeOrphans() cannot sweep it up either: there is
     * no dangling row afterwards, just a file that no row has pointed at since
     * the delete. This test documents that as it stands today.
     */
    public function testReplaceKindOrphansTheBytesOfTheRowItDeletes(): void
    {
        $contractId = $this->makeContract();

        $oldPath = $this->putBytes();
        $this->files->attach($contractId, 'signature', 'first.png', 'image/png', $oldPath);

        $this->files->replaceKind($contractId, 'signature', 'second.png', 'image/png', $this->putBytes());

        self::assertFileExists(
            $oldPath,
            'This asserts current behaviour, not desired behaviour — see the docblock above.'
        );
    }

    // --- purgeForContracts() ---------------------------------------------------

    public function testPurgeForContractsRemovesRowsAndBytesForTheGivenContractsOnly(): void
    {
        $toErase = $this->makeContract();
        $toKeep  = $this->makeContract();

        $erasedPath = $this->putBytes();
        $keptPath   = $this->putBytes();

        $this->files->attach($toErase, 'id_card', 'erase.jpg', 'image/jpeg', $erasedPath);
        $this->files->attach($toKeep, 'id_card', 'keep.jpg', 'image/jpeg', $keptPath);

        $removed = $this->files->purgeForContracts([$toErase]);

        self::assertSame(1, $removed);
        self::assertSame([], $this->files->forContract($toErase));
        self::assertFileDoesNotExist($erasedPath);

        self::assertCount(1, $this->files->forContract($toKeep));
        self::assertFileExists($keptPath);
    }

    public function testPurgeForContractsOfAnEmptyListTouchesNothing(): void
    {
        self::assertSame(0, $this->files->purgeForContracts([]));
    }

    // --- purgeOrphans() ----------------------------------------------------------

    /**
     * files.contract_id carries an ON DELETE CASCADE foreign key (see
     * AddForeignKeys), so deleting a contract the ordinary way already takes
     * its file rows with it — purgeOrphans() cannot observe that path today.
     * The row it exists to clean up — a file whose contract disappeared
     * without the cascade running — is still possible on a site where the
     * constraint failed to apply (AddForeignKeys logs that and continues
     * rather than blocking activation). FK checks are disabled for one insert
     * below to reproduce that shape; it is the only way left to exercise the
     * method's own query.
     */
    public function testPurgeOrphansRemovesRowsAndBytesForContractsThatNoLongerExist(): void
    {
        global $wpdb;

        $orphanPath       = $this->putBytes();
        $ghostContractId  = PHP_INT_MAX - random_int(1, 1000000);

        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
        $wpdb->insert(Tables::name(Tables::FILES), [
            'contract_id' => $ghostContractId,
            'doc_kind'    => 'id_card',
            'filename'    => 'orphan.jpg',
            'mime'        => 'image/jpeg',
            'path'        => $orphanPath,
            'protected'   => 1,
        ]);
        $wpdb->query('SET FOREIGN_KEY_CHECKS=1');

        $survivorContractId = $this->makeContract();
        $survivorPath        = $this->putBytes();
        $survivorFileId      = $this->files->attach(
            $survivorContractId,
            'id_card',
            'ok.jpg',
            'image/jpeg',
            $survivorPath
        );

        $removed = $this->files->purgeOrphans();

        self::assertSame(1, $removed);
        self::assertFileDoesNotExist($orphanPath);

        self::assertNotNull($this->fileRow($survivorFileId));
        self::assertFileExists($survivorPath);
    }

    // --- unprotectedCount() -------------------------------------------------------

    /**
     * unprotectedCount()'s WHERE clause is `protected = 0 OR protected IS
     * NULL`, but EnsureLegacyColumns defines the column `TINYINT NOT NULL
     * DEFAULT 0` — on this schema no row can ever hold NULL, so that half of
     * the condition cannot be exercised here. Confirmed the hard way: this
     * test originally inserted a NULL row and asserted a count of 2; MySQL
     * silently dropped that insert under the NOT NULL constraint and the
     * count came back 1. Left as a schema fact rather than a code change —
     * the OR-NULL branch is presumably defensive for an older install where
     * the column allowed it, and this test covers the state the current
     * schema can actually produce.
     */
    public function testUnprotectedCountCountsZeroButNotOne(): void
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

        self::assertSame(2, $this->files->unprotectedCount());
    }

    // --- protectBatch() -------------------------------------------------------------

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

        $report = $this->files->protectBatch(10);

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

        $report = $this->files->protectBatch(10);

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

        $report = $this->files->protectBatch(10);

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

        $report = $this->files->protectBatch(2);

        self::assertSame(2, $report['protected']);
        self::assertSame(1, $this->files->unprotectedCount());
    }

    // --- Fixtures --------------------------------------------------------------

    /** One provider for the whole test, reused by every contract. */
    private function makeProvider(): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug' => 'ecrm-filerepo-test-' . wp_generate_password(8, false),
            'name' => 'Δοκιμαστικός Πάροχος',
        ]);

        $providerId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $providerId, 'The provider fixture was not inserted.');

        return $providerId;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeContract(array $overrides = []): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), array_merge([
            'customer_id'     => (new CustomerRepository())->create($this->customerData()),
            'partner_user_id' => $this->makePartner(),
            'provider_id'     => $this->providerId,
            'status'          => 'new',
            'energy_type'     => 'power',
            'code'            => 'ECRM-FR-' . wp_generate_password(6, false),
        ], $overrides));

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
            'post_title'     => 'ecrm-filerepo-test-legacy',
            'post_status'    => 'inherit',
        ], $bits['file']);

        self::assertIsInt($attachmentId, 'Could not create the attachment fixture.');
        self::assertGreaterThan(0, $attachmentId);

        update_attached_file($attachmentId, $bits['file']);

        $this->attachmentIds[] = $attachmentId;

        return $attachmentId;
    }

    /** Bytes on disk with no row pointing at them, for tests that attach separately. */
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
