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
 *   - purgeOrphans() — no test touched it.
 *
 * This file covers those, plus one thing found while writing it and fixed in
 * the same commit: replaceKind() deleted a row without unlinking its bytes.
 * See testReplaceKindDeletesTheBytesOfTheRowItReplaces() and the docblock on
 * replaceKind() itself.
 *
 * *Τα unprotectedCount() και protectBatch() καλύπτονταν επίσης εδώ μέχρι τις
 * 2026-08-15, οπότε μετακόμισαν αυτούσια στο `UnprotectedDocumentsTest` μαζί
 * με την κλάση τους. Καμία assertion δεν άλλαξε — μόνο ποιον καλούν.*
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

    /** @var array<string, true> */
    private array $filesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The instance the plugin wired up at boot: same table names and the
        // same protected directory every real caller uses.
        $this->files = Services::files();

        $this->filesBefore = self::documentsOnDisk();
        $this->providerId  = $this->makeProvider();
    }

    protected function tearDown(): void
    {
        foreach (array_keys(array_diff_key(self::documentsOnDisk(), $this->filesBefore)) as $path) {
            wp_delete_file($path);
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
     * Found while writing this net, fixed in the same commit.
     *
     * Every other removal path in this class — purgeGenerated(),
     * purgeForContracts(), purgeOrphans() — called deleteBytes() before it
     * deleted rows. replaceKind() did not: it deleted the old row and inserted
     * a new one, leaving the bytes the old row pointed at never unlinked. That
     * was exactly "a caller removing half" — the failure mode the class header
     * says this class exists to prevent.
     *
     * ECRM_Tracking::rest_sign() calls replaceKind() on every signature, so a
     * customer signing twice would have left the first drawing on disk,
     * referenced by nothing — invisible to purgeOrphans() too, since there was
     * never a dangling row, just a file nothing pointed at after the delete.
     * This is the regression test for that fix.
     *
     * (Ο καλών ήταν ο SigningController μέχρι τις 18/08. Όταν εκείνος
     * διαγράφηκε ως ορφανός, η rest_sign() σταμάτησε να κάνει σκέτο insert και
     * πήρε τη θέση του — αλλιώς η replaceKind() θα έμενε χωρίς καλούντα και
     * αυτό το test θα φύλαγε νεκρό κώδικα.)
     */
    public function testReplaceKindDeletesTheBytesOfTheRowItReplaces(): void
    {
        $contractId = $this->makeContract();

        $oldPath = $this->putBytes();
        $this->files->attach($contractId, 'signature', 'first.png', 'image/png', $oldPath);

        $this->files->replaceKind($contractId, 'signature', 'second.png', 'image/png', $this->putBytes());

        self::assertFileDoesNotExist($oldPath);
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
}
