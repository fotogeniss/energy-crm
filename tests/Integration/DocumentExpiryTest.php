<?php

/**
 * Η στήλη files.expires_at (0026) και το ECRM_Docs που τη διαβάζει.
 *
 * Τρίτο από τα τρία ευρήματα του ελέγχου αυτοματοποίησης (31/08/2026): μια
 * ταυτότητα που έχει λήξει περνούσε το ίδιο checklist με μια φρέσκια, γιατί
 * το `checklist()`/`missing_labels()` ελέγχουν μόνο ΠΑΡΟΥΣΙΑ ενός doc_kind,
 * ποτέ εγκυρότητα. Αυτό το net δοκιμάζει τη νέα στρώση από πάνω --
 * `ECRM_Docs::expired_docs()` -- χωρίς να ξαναγράφει το checklist() που ήδη
 * καλύπτεται αλλού.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Docs;
use ECRM_Files;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class DocumentExpiryTest extends IntegrationTestCase
{
    private int $providerId;

    /** @var array<string, true> */
    private array $filesBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

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

    public function testAnIdCardPastItsExpiryDateIsReported(): void
    {
        $contractId = $this->makeContract();

        Services::files()->attach(
            $contractId,
            'id_card',
            'id.jpg',
            'image/jpeg',
            $this->putBytes(),
            '2020-01-01'
        );

        $expired = ECRM_Docs::expired_docs($contractId);

        self::assertCount(1, $expired);
        self::assertSame('id_card', $expired[0]['kind']);
        self::assertSame('2020-01-01', $expired[0]['expires_at']);
    }

    public function testAnIdCardNotYetExpiredIsNotReported(): void
    {
        $contractId = $this->makeContract();

        Services::files()->attach(
            $contractId,
            'id_card',
            'id.jpg',
            'image/jpeg',
            $this->putBytes(),
            gmdate('Y-m-d', strtotime('+2 years'))
        );

        self::assertSame([], ECRM_Docs::expired_docs($contractId));
    }

    /**
     * provider_bill δεν είναι στο ECRM_Docs::expirable_kinds() -- ακόμα κι αν
     * κάποιος γράψει μια ημερομηνία στο expires_at του (π.χ. λάθος ή μελλοντική
     * επέκταση του πεδίου), δεν πρέπει να μπλοκάρει καμία μετάβαση σήμερα.
     */
    public function testAPastDateOnANonExpirableKindIsIgnored(): void
    {
        $contractId = $this->makeContract();

        Services::files()->attach(
            $contractId,
            'provider_bill',
            'bill.pdf',
            'application/pdf',
            $this->putBytes(),
            '2020-01-01'
        );

        self::assertSame([], ECRM_Docs::expired_docs($contractId));
    }

    /**
     * Ίδιο σκεπτικό με το FileRepository::latestPathOfKind(): μόνο το πιο
     * πρόσφατο έγγραφο ανά είδος μετράει. Μια νέα, έγκυρη ταυτότητα πάνω από
     * μια παλιά ληγμένη σβήνει το πρόβλημα.
     */
    public function testOnlyTheNewestDocumentOfAKindIsChecked(): void
    {
        $contractId = $this->makeContract();
        $files      = Services::files();

        $files->attach($contractId, 'id_card', 'old.jpg', 'image/jpeg', $this->putBytes(), '2020-01-01');
        $files->attach(
            $contractId,
            'id_card',
            'new.jpg',
            'image/jpeg',
            $this->putBytes(),
            gmdate('Y-m-d', strtotime('+2 years'))
        );

        self::assertSame([], ECRM_Docs::expired_docs($contractId));
    }

    public function testADocumentWithNoExpiryRecordedIsNeverReported(): void
    {
        $contractId = $this->makeContract();

        Services::files()->attach($contractId, 'id_card', 'id.jpg', 'image/jpeg', $this->putBytes());

        self::assertSame([], ECRM_Docs::expired_docs($contractId));
    }

    // --- Fixtures --------------------------------------------------------------

    private function makeProvider(): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug' => 'ecrm-docexpiry-test-' . wp_generate_password(8, false),
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
            'code'            => 'ECRM-DE-' . wp_generate_password(6, false),
        ]);

        $contractId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $contractId, 'The contract fixture was not inserted.');

        return $contractId;
    }

    private function putBytes(): string
    {
        $saved = ECRM_Files::put_bytes('fixture bytes ' . wp_generate_password(8, false), 'jpg', 'image/jpeg', 'x.jpg');

        self::assertIsArray($saved, 'Fixture failed to write bytes to protected storage.');

        return (string) $saved['path'];
    }
}
