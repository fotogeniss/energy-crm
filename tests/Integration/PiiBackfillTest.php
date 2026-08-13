<?php

/**
 * The sweep that converts rows written before encryption was switched on.
 *
 * Three properties matter more than the rest, and each has a test whose failure
 * would be a silent data problem rather than a visible one:
 *
 *  - `afm_hash` is not touched. Hashing the ciphertext would leave an index
 *    that matches nothing, and a duplicate check that matches nothing reads as
 *    "no duplicate exists".
 *  - The sweep refuses to run with encryption off, and the cursor does not
 *    move. A cursor that advanced past rows nobody encrypted would mark them
 *    permanently done.
 *  - Selection is an OR across every encrypted column, not the ΑΦΜ. On the
 *    development database 2 of 35 rows carry an ΑΦΜ and 27 carry an ΑΔΤ.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Infrastructure\PiiBackfill;
use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\PiiBackfillRepository;
use EnergyCRM\Persistence\Tables;

final class PiiBackfillTest extends IntegrationTestCase
{
    private PiiBackfill $backfill;

    private PiiBackfillRepository $rows;

    /**
     * One partner for the whole test, not one per fixture.
     *
     * wp_insert_user() hashes a password, which costs a second or two. Making
     * a fresh owner for every contract added about sixteen seconds to a suite
     * that runs before every commit, and none of these tests care who owns the
     * row.
     */
    private ?int $partnerId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! FieldCipher::isAvailable()) {
            self::markTestSkipped('Η επέκταση sodium λείπει από αυτή την PHP.');
        }

        $this->partnerId = null;
        $this->rows      = PiiBackfillRepository::default();
        $this->backfill  = new PiiBackfill($this->rows);
    }

    private function partner(): int
    {
        return $this->partnerId ??= $this->makePartner();
    }

    /**
     * A customer as one looked before encryption existed: plaintext columns,
     * and a blind index already filled in.
     *
     * The index matters to the fixture. Migration 0010 hashed every existing
     * ΑΦΜ, so a real pre-encryption row has a correct `afm_hash` — and a
     * fixture without one would let the sweep corrupt the index while the test
     * that watches for exactly that still passed.
     *
     * @param array<string, string> $overrides
     */
    private function plaintextCustomer(array $overrides = []): int
    {
        global $wpdb;

        $data = array_merge($this->customerData(), $overrides);
        $afm  = (string) ($data['afm'] ?? '');

        if ($afm !== '') {
            $data[CustomerFields::INDEX_COLUMN] = CustomerFields::default()->index($afm);
        }

        $wpdb->insert(Tables::name(Tables::CUSTOMERS), $data);

        $customerId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $customerId, 'Το fixture πελάτη δεν εισήχθη.');

        return $customerId;
    }

    private function contractWithExtras(string $extras): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $this->partner(),
            'status'          => 'signed',
            'energy_type'     => 'power',
            'extra_json'      => $extras,
        ]);

        $contractId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $contractId, 'Το fixture συμβολαίου δεν εισήχθη.');

        return $contractId;
    }

    // ---------------------------------------------------------------- guards

    public function testWithEncryptionOffNothingIsTouchedAndTheCursorStaysPut(): void
    {
        $customerId = $this->plaintextCustomer();
        $contractId = $this->contractWithExtras((string) wp_json_encode(['rep_first_name' => 'Γιώργος']));

        $bagBefore = (string) $this->storedRow(Tables::CONTRACTS, $contractId)['extra_json'];

        $report = $this->backfill->sweep();

        self::assertNotNull($report['blocked']);
        self::assertSame(0, $report['customers']);
        self::assertSame(0, $report['contracts']);

        self::assertSame('123456789', (string) $this->storedRow(Tables::CUSTOMERS, $customerId)['afm']);

        // Byte-for-byte what it was. Asserting that it "contains" the name
        // would have been asserting the wrong thing anyway — and would have
        // failed on wp_json_encode's \uXXXX escaping rather than on the
        // behaviour, which is how this test first went red.
        self::assertSame(
            $bagBefore,
            (string) $this->storedRow(Tables::CONTRACTS, $contractId)['extra_json']
        );

        // The one that would be permanent: a cursor past unencrypted rows.
        self::assertSame(0, $this->rows->cursor());
    }

    // ------------------------------------------------------------- customers

    /** The property the rest of the mechanism depends on. */
    public function testTheBlindIndexIsIdenticalBeforeAndAfterTheSweep(): void
    {
        $this->encryptionOn();

        $id     = $this->plaintextCustomer();
        $before = (string) $this->storedRow(Tables::CUSTOMERS, $id)['afm_hash'];

        self::assertNotSame('', $before, 'Το fixture δεν έχει afm_hash — το test δεν θα απεδείκνυε τίποτα.');

        $this->backfill->sweep();

        $after = $this->storedRow(Tables::CUSTOMERS, $id);

        self::assertTrue(FieldCipher::isEncrypted((string) $after['afm']));
        self::assertSame($before, (string) $after['afm_hash']);
    }

    public function testEveryEncryptedColumnIsConverted(): void
    {
        $this->encryptionOn();

        $id = $this->plaintextCustomer();

        self::assertSame(1, $this->backfill->sweep()['customers']);

        $row = $this->storedRow(Tables::CUSTOMERS, $id);

        foreach (['afm', 'adt', 'street', 'street_no', 'postal_code'] as $column) {
            self::assertTrue(FieldCipher::isEncrypted((string) $row[$column]), "Η στήλη {$column} έμεινε καθαρή.");
        }

        // Not on the list, and must stay reportable.
        self::assertSame('Θεσσαλονίκη', (string) $row['city']);
        self::assertSame('Γιώργος', (string) $row['first_name']);
    }

    /**
     * The finding that shaped the selection clause.
     *
     * A row with no ΑΦΜ but an ΑΔΤ is the common case in the real data, not the
     * edge case. Selecting on the ΑΦΜ would skip it and report success.
     */
    public function testARowWithNoTaxNumberButAnIdCardIsStillPickedUp(): void
    {
        $this->encryptionOn();

        $id = $this->plaintextCustomer(['afm' => '', 'adt' => 'ΑΒ999999']);

        self::assertSame(1, $this->backfill->pending()['customers']);
        self::assertSame(1, $this->backfill->sweep()['customers']);

        $row = $this->storedRow(Tables::CUSTOMERS, $id);

        self::assertTrue(FieldCipher::isEncrypted((string) $row['adt']));
        self::assertSame('', (string) $row['afm'], 'Το κενό ΑΦΜ δεν έπρεπε να γίνει bytes.');
    }

    public function testASecondSweepFindsNothingLeftToDo(): void
    {
        $this->encryptionOn();

        $this->plaintextCustomer();

        self::assertSame(1, $this->backfill->sweep()['customers']);
        self::assertSame(0, $this->backfill->pending()['customers']);
        self::assertSame(0, $this->backfill->sweep()['customers']);
    }

    /**
     * Interrupted halfway is a normal state.
     *
     * A batch of one leaves the table mixed, which is exactly what a crashed
     * run leaves behind. The next slice must carry on rather than start over or
     * choke on the rows already done.
     */
    public function testAnInterruptedRunResumesWhereItStopped(): void
    {
        $this->encryptionOn();

        $first  = $this->plaintextCustomer(['afm' => '111111111']);
        $second = $this->plaintextCustomer(['afm' => '222222222']);

        self::assertSame(1, $this->backfill->sweep(1)['customers']);
        self::assertSame(1, $this->backfill->pending()['customers']);

        self::assertSame(1, $this->backfill->sweep(1)['customers']);
        self::assertSame(0, $this->backfill->pending()['customers']);

        foreach ([$first, $second] as $id) {
            self::assertTrue(FieldCipher::isEncrypted((string) $this->storedRow(Tables::CUSTOMERS, $id)['afm']));
        }
    }

    // ------------------------------------------------------------- contracts

    public function testPersonalValuesInTheBagAreEncryptedAndTheRestIsNot(): void
    {
        $this->encryptionOn();

        $id = $this->contractWithExtras((string) wp_json_encode([
            'rep_first_name' => 'Γιώργος',
            'agreed_power'   => '8',
        ]));

        self::assertSame(1, $this->backfill->sweep()['contracts']);

        $bag = json_decode((string) $this->storedRow(Tables::CONTRACTS, $id)['extra_json'], true);

        self::assertIsArray($bag);
        self::assertTrue(FieldCipher::isEncrypted((string) $bag['rep_first_name']));
        self::assertSame('8', $bag['agreed_power']);
    }

    /**
     * The cursor moves past rows with nothing to do.
     *
     * Advancing only on change would stall the walk on the first empty bag and
     * re-read it every hour, for ever.
     */
    public function testTheWalkPassesOverContractsWithNothingToEncrypt(): void
    {
        $this->encryptionOn();

        $empty       = $this->contractWithExtras('');
        $alsoNothing = $this->contractWithExtras((string) wp_json_encode(['agreed_power' => '8']));

        self::assertSame(0, $this->backfill->sweep()['contracts']);

        self::assertGreaterThanOrEqual(
            $alsoNothing,
            $this->rows->cursor(),
            'Ο δείκτης κόλλησε — το επόμενο πέρασμα θα ξαναδιάβαζε τις ίδιες γραμμές.'
        );
        self::assertGreaterThan(0, $empty);
    }

    public function testTheWalkReportsWhatIsLeftToVisit(): void
    {
        $this->encryptionOn();

        $this->contractWithExtras((string) wp_json_encode(['rep_first_name' => 'Α']));
        $this->contractWithExtras((string) wp_json_encode(['rep_first_name' => 'Β']));

        self::assertGreaterThanOrEqual(2, $this->backfill->pending()['contracts']);

        $this->backfill->sweep();

        self::assertSame(0, $this->backfill->pending()['contracts']);
    }
}
