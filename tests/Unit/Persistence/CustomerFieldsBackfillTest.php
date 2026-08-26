<?php

/**
 * The narrow path the backfill comes in through, and the index it must not touch.
 *
 * forStorage() derives `afm_hash` from the plaintext ΑΦΜ it is handed. Handed a
 * row read back out of the database, where the ΑΦΜ is already ciphertext, it
 * would hash *that* and write it over a correct index. Nothing errors. The next
 * duplicate check matches no rows, an agent reads that as "no duplicate", and
 * the same customer is filed twice.
 *
 * So these tests exist to pin one property above all the others:
 * encryptStoredColumns() never returns the index column. Everything else here
 * is about not doing needless writes.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Persistence;

use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Persistence\CustomerFields;
use PHPUnit\Framework\TestCase;

final class CustomerFieldsBackfillTest extends TestCase
{
    private function fields(): CustomerFields
    {
        return new CustomerFields(new FieldCipher('a-wp-config-salt'));
    }

    /**
     * Skipped, never quietly passed — the same rule FieldCipherTest follows.
     * Without sodium there is nothing to assert, and a green tick would lie.
     */
    private function requireSodium(): void
    {
        if (! FieldCipher::isAvailable()) {
            self::markTestSkipped('Η επέκταση sodium λείπει από αυτή την PHP.');
        }
    }

    /** The property the whole backfill rests on. */
    public function testTheBlindIndexIsNeverAmongTheColumnsToWrite(): void
    {
        $this->requireSodium();

        $changes = $this->fields()->encryptStoredColumns([
            'id'                        => 7,
            'afm'                       => '123456789',
            CustomerFields::INDEX_COLUMN => 'the-hash-that-is-already-correct',
        ]);

        self::assertArrayNotHasKey(CustomerFields::INDEX_COLUMN, $changes);
        self::assertArrayHasKey('afm', $changes);
    }

    public function testPlaintextColumnsComeBackAsCiphertext(): void
    {
        $this->requireSodium();

        $changes = $this->fields()->encryptStoredColumns([
            'afm'         => '123456789',
            'adt'         => 'ΑΒ123456',
            'street'      => 'Αγίου Δημητρίου',
            'street_no'   => '14',
            'postal_code' => '54630',
        ]);

        self::assertCount(5, $changes);

        foreach ($changes as $column => $value) {
            self::assertTrue(FieldCipher::isEncrypted($value), "Η στήλη {$column} έμεινε καθαρή.");
        }
    }

    /**
     * A row that is already done costs no write.
     *
     * An idle UPDATE bumps updated_at, and updated_at is what the lists sort
     * by — a backfill that rewrote every row would reshuffle every screen.
     */
    public function testARowThatIsAlreadyEncryptedYieldsNothingToWrite(): void
    {
        $this->requireSodium();

        $fields = $this->fields();
        $first  = $fields->encryptStoredColumns(['afm' => '123456789']);

        self::assertSame([], $fields->encryptStoredColumns(['afm' => $first['afm']]));
    }

    /** Interrupted halfway is a normal state, not a broken one. */
    public function testAHalfEncryptedRowFinishesTheColumnsThatAreLeft(): void
    {
        $this->requireSodium();

        $fields    = $this->fields();
        $encrypted = $fields->encryptStoredColumns(['afm' => '123456789'])['afm'];

        $changes = $fields->encryptStoredColumns([
            'afm' => $encrypted,
            'adt' => 'ΑΒ123456',
        ]);

        self::assertSame(['adt'], array_keys($changes));
    }

    /**
     * Empty is not a value.
     *
     * Encrypting '' would turn "we never asked" into bytes that look like an
     * answer — the reasoning FieldCipher::encrypt() already applies, asserted
     * here at the level the backfill uses.
     */
    public function testEmptyAndMissingColumnsAreLeftAlone(): void
    {
        $this->requireSodium();

        self::assertSame([], $this->fields()->encryptStoredColumns([
            'afm'    => '',
            'adt'    => null,
            'street' => '',
        ]));
    }

    /** Columns outside the encrypted list are none of this method's business. */
    public function testColumnsThatAreNotEncryptedAreNotReturned(): void
    {
        $this->requireSodium();

        $changes = $this->fields()->encryptStoredColumns([
            'afm'        => '123456789',
            'first_name' => 'Γιώργος',
            'city'       => 'Θεσσαλονίκη',
            'mobile'     => '6912340000',
        ]);

        self::assertSame(['afm'], array_keys($changes));
    }

    /**
     * The list is asked for, not copied.
     *
     * If a column is added to CustomerFields and this drifts, the backfill's
     * SQL would stop selecting rows that still need work — silently, because
     * a row it never selects is indistinguishable from a row already done.
     */
    public function testTheEncryptedColumnListIsTheOneTheClassUses(): void
    {
        self::assertSame(
            ['afm', 'adt', 'street', 'street_no', 'postal_code', 'phone'],
            CustomerFields::encryptedColumns()
        );
    }
}
