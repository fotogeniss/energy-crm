<?php

/**
 * A write that would land under the wrong key is refused, and only that write.
 *
 * KeyFingerprint (CHANGELOG 2026-08-16 (12)) made a rotated salt visible. This
 * is the half that acts on it, and the interesting question was never "does it
 * refuse" — it is *how much* it refuses.
 *
 * Under a rotated key exactly one thing is unsafe: overwriting the protected
 * columns, because they read as empty and a save would make that emptiness
 * permanent over ciphertext that is otherwise intact on disk. Everything else
 * about the CRM is fine. So a refusal that covered every write would be an
 * outage, and an outage is worked around — someone turns the flag off, and the
 * next save writes ΑΦΜ in plaintext.
 *
 * Test 2 is therefore the one that matters most. Tests 1, 3 and 4 prove the
 * safeguard fires; test 2 proves it did not become the disaster it prevents.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\KeyFingerprint;
use EnergyCRM\Infrastructure\PiiBackfill;
use EnergyCRM\Infrastructure\RotatedKey;
use EnergyCRM\Persistence\ContractFields;
use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\PiiBackfillRepository;

final class RotatedKeyRefusalTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->encryptionOn();
        delete_option(KeyFingerprint::OPTION);
    }

    protected function tearDown(): void
    {
        delete_option(KeyFingerprint::OPTION);

        parent::tearDown();
    }

    /** 1. A customer row carrying an ΑΦΜ is refused under a foreign stamp. */
    public function testWritingAProtectedColumnUnderAnotherKeyIsRefused(): void
    {
        $this->stampAnotherKey();

        $this->expectException(RotatedKey::class);

        CustomerFields::default()->forStorage(['afm' => '090003373']);
    }

    /**
     * 2. A row that touches nothing protected still saves.
     *
     * The line between a safeguard and an outage. A phone number cannot be
     * lost by a key that never encrypted it, and refusing this save would stop
     * the CRM for a reason that does not apply to it.
     */
    public function testARowWithNoProtectedColumnsIsUntouchedByTheGuard(): void
    {
        $this->stampAnotherKey();

        $row = CustomerFields::default()->forStorage([
            'first_name' => 'Χωρίς',
            'last_name'  => 'Προστατευμένα',
            'phone'      => '2310000000',
        ]);

        self::assertSame('2310000000', $row['phone'], 'Η γραμμή έπρεπε να περάσει ανέπαφη.');
    }

    /** 3. The extras bag is the other half of what encryption covers. */
    public function testWritingTheExtrasBagUnderAnotherKeyIsRefused(): void
    {
        $this->stampAnotherKey();

        $this->expectException(RotatedKey::class);

        ContractFields::default()->forStorage(['extra_json' => '{"agreed_power":"8"}']);
    }

    /**
     * 4. The backfill refuses itself.
     *
     * Under a rotated key it is the most destructive thing on the site: it
     * walks every row deliberately, and what it would write is the blanks the
     * wrong key is already reading. docs/BACKUP.md says not to run it — this
     * is that instruction with teeth, for the reader who never got there.
     */
    public function testTheBackfillRefusesToRunUnderAnotherKey(): void
    {
        $this->stampAnotherKey();

        $blocked = (new PiiBackfill(PiiBackfillRepository::default()))->blockedReason();

        self::assertNotNull($blocked, 'Το backfill έπρεπε να αρνηθεί να ξεκινήσει.');
        self::assertStringContainsString('BACKUP.md', $blocked, 'Η άρνηση πρέπει να λέει πού είναι οι οδηγίες.');
    }

    /**
     * 5. With the site's own stamp, nothing changes.
     *
     * The state every real site is in. If this were red the guard would be
     * refusing correct writes, which no other test here would notice — all
     * four above pass just as happily with a safeguard that fires always.
     */
    public function testTheSitesOwnKeyIsAllowedThrough(): void
    {
        KeyFingerprint::default()->remember();

        $row = CustomerFields::default()->forStorage(['afm' => '090003373']);

        self::assertArrayHasKey(CustomerFields::INDEX_COLUMN, $row, 'Ο δείκτης έπρεπε να υπολογιστεί κανονικά.');
        self::assertNotSame('', $row[CustomerFields::INDEX_COLUMN]);
    }

    /** A stamp that is definitely not this site's key. */
    private function stampAnotherKey(): void
    {
        update_option(KeyFingerprint::OPTION, str_repeat('b', 64));

        self::assertFalse(
            KeyFingerprint::default()->matches(),
            'Το fixture δεν έστησε αναντιστοιχία, οπότε τα tests από κάτω δεν μετρούν τίποτα.'
        );
    }
}
