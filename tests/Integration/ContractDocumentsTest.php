<?php

/**
 * What storing a contract's document actually does.
 *
 * Characterisation, written before the code moved: every assertion here
 * described `ECRM_REST::store_contract_pdf()` exactly as it stood. The move out
 * to ContractDocuments then changed one thing in this file — the name of what
 * gets called — and nothing else. That is what made it safe to make.
 *
 * It earns its place regardless. This method decides what the customer signs
 * and what the provider receives, it deletes files off disk, and nothing
 * covered it.
 *
 * ## Two defects this file deliberately does not assert yet
 *
 * Reading the method beside its neighbours turned up two, and neither is
 * visible through this seam:
 *
 *   - It reads the contract with raw SQL and skips CustomerFields and
 *     ContractFields, so with ECRM_ENCRYPT_PII on the *stored* form prints
 *     `ecrm1:…` where the ΑΦΜ belongs. The download route reads the same
 *     columns through findDetailed() and prints them correctly, which is why
 *     nobody has seen it.
 *   - It renders without a signature path, so the copy rebuilt immediately
 *     after the customer signs does not carry the signature — the one thing
 *     that rebuild exists to add.
 *
 * Both were invisible from here because the evidence ends up inside a
 * compressed PDF stream. SheetRenderer is an interface for exactly that
 * reason: with a collaborator, "was it handed the signature?" and "was the row
 * decrypted?" have answers. Those tests are at the bottom of this file.
 *
 * ## Files outlive the transaction
 *
 * Rows roll back, bytes on disk do not. Whatever a test leaves in the
 * protected directory is removed in tearDown, by comparing the directory
 * against how it looked before the test ran.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Infrastructure\ContractDocuments;
use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Infrastructure\SheetRenderer;
use EnergyCRM\Persistence\ContractDetails;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class ContractDocumentsTest extends IntegrationTestCase
{
    /**
     * A provider with no bundled template on purpose.
     *
     * ECRM_FormFill then returns nothing and the internal summary is built
     * instead — one page, no background images. That is the cheap path, and
     * every assertion in this file is about what happens to the bytes
     * afterwards, not about which renderer produced them.
     */
    private const PROVIDER = 'Δοκιμαστικός Πάροχος';

    private ContractDocuments $documents;

    private int $providerId;

    private int $contractId;

    /** @var list<string> */
    private array $filesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The instance the plugin wired up at boot, so the test exercises the
        // same object graph the cron job and the signing page reach.
        $this->documents = Services::contractDocuments();

        $this->filesBefore = $this->storageContents();
        $this->providerId  = $this->makeProvider();
        $this->contractId  = $this->makeContract();
    }

    protected function tearDown(): void
    {
        foreach (array_diff($this->storageContents(), $this->filesBefore) as $path) {
            wp_delete_file($path);
        }

        parent::tearDown();
    }

    public function testTheDocumentIsStoredAgainstTheContract(): void
    {
        self::assertTrue($this->documents->store($this->contractId));

        $documents = $this->documentsFor($this->contractId);

        self::assertCount(1, $documents);
        self::assertSame('contract', $documents[0]['doc_kind']);
    }

    /**
     * Stored where the web server refuses to serve it.
     *
     * `protected = 1` is not decoration: without it serve() looks for a media
     * attachment instead, and the document is unreachable — or, on a row
     * written before the secure directory existed, publicly reachable.
     */
    public function testTheStoredDocumentIsFlaggedProtected(): void
    {
        $this->documents->store($this->contractId);

        self::assertSame('1', (string) $this->documentsFor($this->contractId)[0]['protected']);
    }

    public function testTheBytesOnDiskAreAPdf(): void
    {
        $this->documents->store($this->contractId);

        $path = (string) $this->documentsFor($this->contractId)[0]['path'];

        self::assertFileExists($path);
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
    }

    /** The agent recognises the file by the contract code, not by a hash. */
    public function testTheFilenameIsTheContractCode(): void
    {
        $this->documents->store($this->contractId);

        self::assertSame('ECRM-TEST-1.pdf', $this->documentsFor($this->contractId)[0]['filename']);
    }

    /** A contract saved before codes were assigned still gets a readable name. */
    public function testAContractWithoutACodeIsNamedAfterItsId(): void
    {
        $id = $this->makeContract(['code' => '']);

        $this->documents->store($id);

        self::assertSame('symvasi-' . $id . '.pdf', $this->documentsFor($id)[0]['filename']);
    }

    /**
     * Rebuilding replaces, and takes the old bytes with it.
     *
     * Leaving them behind is how this plugin accumulated the orphaned
     * documents FileRepository::purgeOrphans() had to sweep up: a row deleted
     * without its file is a scanned ID card nobody can see and nobody deletes.
     */
    public function testARebuildReplacesTheDocumentAndDeletesTheOldBytes(): void
    {
        $this->documents->store($this->contractId);

        $first = $this->documentsFor($this->contractId)[0];

        $this->documents->store($this->contractId);

        $documents = $this->documentsFor($this->contractId);

        self::assertCount(1, $documents);
        self::assertNotSame((int) $first['id'], (int) $documents[0]['id']);
        self::assertFileDoesNotExist((string) $first['path']);
    }

    /**
     * A rebuild touches only what a build produced.
     *
     * Everything the customer or the agent uploaded has its own doc_kind, and
     * losing an ID card because somebody pressed save twice would be
     * unrecoverable — the original was never anywhere else.
     */
    public function testARebuildLeavesUploadedDocumentsAlone(): void
    {
        $uploaded = $this->attachDocument($this->contractId, 'id_card');

        $this->documents->store($this->contractId);
        $this->documents->store($this->contractId);

        $kinds = array_column($this->documentsFor($this->contractId), 'doc_kind');

        self::assertContains('id_card', $kinds);
        self::assertFileExists($uploaded);
    }

    public function testAContractThatDoesNotExistStoresNothing(): void
    {
        self::assertFalse($this->documents->store($this->contractId + 100000));

        self::assertSame([], $this->documentsFor($this->contractId + 100000));
    }

    // --- What the renderer is handed ---------------------------------------

    /**
     * The signature reaches the form.
     *
     * It did not, for as long as the feature existed. ECRM_Tracking rebuilds
     * the document immediately after the customer signs, with a comment saying
     * the copy "now carries the signature" — and called fill_all() without a
     * path to it. The provider therefore received an unsigned application while
     * the agent, whose download route does pass one, saw a signed one.
     */
    public function testTheRendererIsHandedTheSignatureTheContractCarries(): void
    {
        $signature = $this->attachDocument($this->contractId, 'signature');
        $renderer  = new RecordingSheetRenderer();

        $this->documentsWith($renderer)->store($this->contractId);

        self::assertSame($signature, $renderer->calls[0]['signaturePath']);
    }

    public function testTheRendererIsHandedNothingWhenNobodyHasSigned(): void
    {
        $renderer = new RecordingSheetRenderer();

        $this->documentsWith($renderer)->store($this->contractId);

        self::assertNull($renderer->calls[0]['signaturePath']);
    }

    /**
     * Signing again does not resurrect the first drawing.
     *
     * The signing page inserts a row per signature rather than replacing one,
     * so a contract signed twice has two. The one that counts is the last.
     */
    public function testTheSignatureHandedOverIsTheMostRecentOne(): void
    {
        $this->attachDocument($this->contractId, 'signature');
        $latest   = $this->attachDocument($this->contractId, 'signature');
        $renderer = new RecordingSheetRenderer();

        $this->documentsWith($renderer)->store($this->contractId);

        self::assertSame($latest, $renderer->calls[0]['signaturePath']);
    }

    /**
     * The row reaches the renderer readable.
     *
     * The old code read the contract with its own copy of findDetailed()'s
     * query and skipped the translation back out of storage, so with
     * encryption on the stored form printed `ecrm1:…` where the ΑΦΜ belongs.
     * The assertion on disk is not decoration: without it this test would pass
     * just as happily if encryption had quietly not been applied at all.
     */
    public function testTheRendererIsHandedTheTaxNumberInPlaintext(): void
    {
        $this->encryptionOn();

        $contractId = $this->makeContract(['code' => 'ECRM-TEST-2']);
        $customerId = (int) $this->storedRow(Tables::CONTRACTS, $contractId)['customer_id'];

        self::assertTrue(
            FieldCipher::isEncrypted((string) $this->storedRow(Tables::CUSTOMERS, $customerId)['afm']),
            'Nothing was encrypted, so this test proves nothing.'
        );

        $renderer = new RecordingSheetRenderer();

        $this->documentsWith($renderer)->store($contractId);

        $handed = $renderer->calls[0]['contract'];

        self::assertSame('123456789', $handed['afm']);
        self::assertSame('ΑΒ123456', $handed['adt']);
        self::assertSame('Αγίου Δημητρίου', $handed['street']);
    }

    // --- Fixtures ----------------------------------------------------------

    /** The same class the plugin wires up, with the renderer swapped out. */
    private function documentsWith(SheetRenderer $renderer): ContractDocuments
    {
        return new ContractDocuments(new ContractDetails(), Services::files(), $renderer);
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
            'code'            => 'ECRM-TEST-1',
        ], $overrides));

        $contractId = (int) $wpdb->insert_id;

        // Said here rather than left to surface three assertions later as a
        // filename of "symvasi-0.pdf": contracts.customer_id and provider_id
        // are foreign keys, so a fixture that quietly produces a zero is
        // rejected by the database and the test reports the wrong thing.
        self::assertGreaterThan(0, $contractId, 'The contract fixture was not inserted.');

        return $contractId;
    }

    /**
     * One provider for the whole test, reused by every contract.
     *
     * `providers.slug` is NOT NULL with a UNIQUE key and no default, so a
     * second provider inserted without one collides with the first on the
     * empty string. Naming it explicitly is what keeps this a fixture rather
     * than a trap for the next test that needs two contracts.
     */
    private function makeProvider(): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug' => 'ecrm-test-' . wp_generate_password(8, false),
            'name' => self::PROVIDER,
        ]);

        $providerId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $providerId, 'The provider fixture was not inserted.');

        return $providerId;
    }

    /** A document that did not come from a build, so a rebuild must not remove it. */
    private function attachDocument(int $contractId, string $kind): string
    {
        global $wpdb;

        $saved = ECRM_Files::put_bytes('not a pdf', 'jpg', 'image/jpeg', 'tautotita.jpg');

        self::assertIsArray($saved);

        $wpdb->insert(Tables::name(Tables::FILES), [
            'contract_id' => $contractId,
            'doc_kind'    => $kind,
            'filename'    => $saved['filename'],
            'mime'        => $saved['mime'],
            'path'        => $saved['path'],
            'protected'   => 1,
        ]);

        return (string) $saved['path'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentsFor(int $contractId): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE contract_id = %d ORDER BY id',
                Tables::name(Tables::FILES),
                $contractId
            ),
            ARRAY_A
        );

        return $rows;
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
