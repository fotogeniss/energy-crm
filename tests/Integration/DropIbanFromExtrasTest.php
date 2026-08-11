<?php

/**
 * The migration that deletes stored bank account numbers.
 *
 * Tested because it is irreversible and because it runs unattended: a migration
 * is applied once, on somebody else's live site, by a request nobody is
 * watching. There is no second chance to notice it took the wrong thing with
 * it, and the rows it touches hold the rest of the provider's answers.
 *
 * What matters here is the pair: that the IBAN goes, and that nothing beside it
 * does. A migration that emptied the whole extras bag would satisfy the first
 * half and lose the agreed power, the meter reading and every question the
 * customer answered.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\Schema\Migrations\DropIbanFromExtras;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class DropIbanFromExtrasTest extends IntegrationTestCase
{
    private const IBAN = 'GR1601101250000000012300695';

    public function testTheBankAccountIsGoneAndTheRestOfTheBagIsNot(): void
    {
        $contractId = $this->contractWithExtras([
            'iban'         => self::IBAN,
            'agreed_power' => '8',
            'guarantee'    => '150',
        ]);

        $this->runMigration();

        $extras = $this->extrasOf($contractId);

        self::assertArrayNotHasKey('iban', $extras);
        self::assertSame('8', $extras['agreed_power'] ?? null);
        self::assertSame('150', $extras['guarantee'] ?? null);
    }

    /**
     * The number itself is gone from the stored text, not just the key.
     *
     * A separate assertion because they can come apart: an encoder that kept
     * the old string around, or a bag nested one level deeper, would satisfy
     * the key check and leave the digits on disk.
     */
    public function testTheDigitsAreNotLeftAnywhereInTheRow(): void
    {
        $contractId = $this->contractWithExtras(['iban' => self::IBAN, 'agreed_power' => '8']);

        $this->runMigration();

        $stored = $this->storedRow(Tables::CONTRACTS, $contractId);

        self::assertStringNotContainsString(self::IBAN, (string) $stored['extra_json']);
    }

    /**
     * Ciphertext is removed the same way plaintext is.
     *
     * The migration deletes the key rather than reading the value, so it needs
     * no key material and cannot quietly skip the rows it fails to decrypt.
     */
    public function testAnEncryptedBankAccountIsRemovedToo(): void
    {
        $contractId = $this->contractWithExtras([
            'iban'         => 'ecrm1:c29tZXRoaW5nIHRoYXQgbG9va3MgZW5jcnlwdGVk',
            'agreed_power' => '8',
        ]);

        $this->runMigration();

        $extras = $this->extrasOf($contractId);

        self::assertArrayNotHasKey('iban', $extras);
        self::assertSame('8', $extras['agreed_power'] ?? null);
    }

    /** A contract that never had one is left exactly as it was. */
    public function testAContractWithoutABankAccountIsNotRewritten(): void
    {
        $contractId = $this->contractWithExtras(['agreed_power' => '8']);

        $before = (string) $this->storedRow(Tables::CONTRACTS, $contractId)['extra_json'];

        $this->runMigration();

        self::assertSame(
            $before,
            (string) $this->storedRow(Tables::CONTRACTS, $contractId)['extra_json']
        );
    }

    /** Running it twice changes nothing the second time. */
    public function testTheMigrationIsSafeToRunAgain(): void
    {
        $contractId = $this->contractWithExtras(['iban' => self::IBAN, 'agreed_power' => '8']);

        $this->runMigration();
        $once = (string) $this->storedRow(Tables::CONTRACTS, $contractId)['extra_json'];

        $this->runMigration();

        self::assertSame(
            $once,
            (string) $this->storedRow(Tables::CONTRACTS, $contractId)['extra_json']
        );
    }

    // --- Fixtures ----------------------------------------------------------

    private function runMigration(): void
    {
        (new DropIbanFromExtras())->apply(new SchemaInspector());
    }

    /**
     * A contract row carrying the given extras bag, written straight to disk.
     *
     * Deliberately not through ContractRepository: the point is to plant the
     * bag exactly as an older version of the form left it, without today's
     * translation layer having an opinion about it.
     *
     * @param array<string, string> $extras
     */
    private function contractWithExtras(array $extras): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $this->makePartner(),
            'status'          => 'signed',
            'energy_type'     => 'power',
            'extra_json'      => (string) wp_json_encode($extras),
        ]);

        $contractId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $contractId, 'The contract fixture was not inserted.');

        return $contractId;
    }

    /** @return array<string, mixed> */
    private function extrasOf(int $contractId): array
    {
        $extras = json_decode(
            (string) $this->storedRow(Tables::CONTRACTS, $contractId)['extra_json'],
            true
        );

        self::assertIsArray($extras);

        /** @var array<string, mixed> $extras */
        return $extras;
    }
}
