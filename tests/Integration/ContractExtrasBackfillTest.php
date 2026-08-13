<?php

/**
 * The extras half of the backfill: personal values inside a bag that is
 * already stored.
 *
 * Integration rather than unit only because the bag is re-encoded with
 * wp_json_encode(). Nothing here touches a table.
 *
 * The property under test is the pair, the same one DropIbanFromExtras had to
 * prove: that the personal values are encrypted, and that nothing beside them
 * moves. A pass that encrypted the whole bag would satisfy the first half and
 * take the agreed power, the meter reading and every price with it — and the
 * erasure filter, which works by key, would stop recognising anything.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Persistence\ContractFields;

final class ContractExtrasBackfillTest extends IntegrationTestCase
{
    private function fields(): ContractFields
    {
        return new ContractFields(new FieldCipher('a-wp-config-salt'));
    }

    private function requireSodium(): void
    {
        if (! FieldCipher::isAvailable()) {
            self::markTestSkipped('Η επέκταση sodium λείπει από αυτή την PHP.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $bag = json_decode($json, true);

        self::assertIsArray($bag);

        /** @var array<string, mixed> $bag */
        return $bag;
    }

    public function testPersonalValuesAreEncryptedAndTheRestIsUntouched(): void
    {
        $this->requireSodium();

        $bag = (string) wp_json_encode([
            'rep_first_name' => 'Γιώργος',
            'adt_epikoinonias' => 'ΑΒ123456',
            'agreed_power'   => '8',
            'payment_method' => 'pagia_entoli',
            'guarantee'      => '120',
        ]);

        $result = $this->fields()->encryptStoredExtras($bag);

        self::assertNotNull($result);

        $after = $this->decode($result);

        self::assertTrue(FieldCipher::isEncrypted((string) $after['rep_first_name']));
        self::assertTrue(FieldCipher::isEncrypted((string) $after['adt_epikoinonias']));

        // Classified as non-personal, so they stay readable — the meter and the
        // money still have to be reportable.
        self::assertSame('8', $after['agreed_power']);
        self::assertSame('pagia_entoli', $after['payment_method']);
        self::assertSame('120', $after['guarantee']);
    }

    /**
     * Default-deny, asserted at this level too.
     *
     * A key nobody has classified is personal. That is what makes it safe to
     * add a provider field without remembering this pass exists.
     */
    public function testAnUnclassifiedKeyIsTreatedAsPersonal(): void
    {
        $this->requireSodium();

        $bag = (string) wp_json_encode(['a_field_invented_tomorrow' => 'κάτι προσωπικό']);

        $result = $this->fields()->encryptStoredExtras($bag);

        self::assertNotNull($result);
        self::assertTrue(FieldCipher::isEncrypted((string) $this->decode($result)['a_field_invented_tomorrow']));
    }

    public function testABagWithNothingPersonalIsLeftAlone(): void
    {
        $this->requireSodium();

        $bag = (string) wp_json_encode(['agreed_power' => '8', 'guarantee' => '120']);

        self::assertNull($this->fields()->encryptStoredExtras($bag));
    }

    /** The second pass costs no write, so the sweep can be re-run safely. */
    public function testASecondPassChangesNothing(): void
    {
        $this->requireSodium();

        $fields = $this->fields();
        $bag    = (string) wp_json_encode(['rep_first_name' => 'Γιώργος', 'agreed_power' => '8']);

        $once = $fields->encryptStoredExtras($bag);

        self::assertNotNull($once);
        self::assertNull($fields->encryptStoredExtras($once));
    }

    /**
     * A bag that will not parse is carried forward, not replaced.
     *
     * Losing a contract's extras to a stray character is worse than keeping a
     * bad value where somebody can see it.
     */
    public function testAnUnparseableBagIsLeftExactlyAsItWas(): void
    {
        $this->requireSodium();

        self::assertNull($this->fields()->encryptStoredExtras('{κάτι που δεν είναι JSON'));
        self::assertNull($this->fields()->encryptStoredExtras(''));
    }

    /** Empty values stay empty: absence is not a secret worth bytes. */
    public function testEmptyPersonalValuesAreNotEncrypted(): void
    {
        $this->requireSodium();

        $bag = (string) wp_json_encode(['rep_first_name' => '', 'rep_last_name' => '']);

        self::assertNull($this->fields()->encryptStoredExtras($bag));
    }
}
