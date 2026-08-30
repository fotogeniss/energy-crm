<?php

/**
 * `Retention` -- ο GDPR-90-μερών καθαρισμός των `extracted_json` payloads.
 *
 * AUDIT εύρημα §2.5 (EKKREMI-29-08.html): "αν σταματήσει, τίποτα δεν
 * κοκκινίζει και οι ταυτότητες συσσωρεύονται". Μέχρι αυτό το αρχείο δεν
 * υπήρχε ΚΑΝΕΝΑ test -- ούτε για το `days()` (default/option/filter), ούτε
 * για το ίδιο το `sweep()`. Αν κάποιος έσπαγε αθόρυτα το φίλτρο ή το SQL,
 * κανένα test δεν θα το έπιανε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Infrastructure\Retention;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;

final class RetentionSweepTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private Retention $retention;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->retention = new Retention($this->contracts);
    }

    protected function tearDown(): void
    {
        delete_option('ecrm_extraction_retention_days');
        remove_all_filters('ecrm_extraction_retention_days');

        parent::tearDown();
    }

    public function testDaysDefaultsToNinetyWithNoOptionSet(): void
    {
        self::assertSame(90, $this->retention->days());
    }

    public function testTheOptionOverridesTheDefault(): void
    {
        update_option('ecrm_extraction_retention_days', 30);

        self::assertSame(30, $this->retention->days());
    }

    public function testTheFilterCanOverrideTheOption(): void
    {
        update_option('ecrm_extraction_retention_days', 30);
        add_filter('ecrm_extraction_retention_days', static fn (): int => 7);

        self::assertSame(7, $this->retention->days());
    }

    public function testANegativeFilterValueIsClampedToZeroNotFlipped(): void
    {
        add_filter('ecrm_extraction_retention_days', static fn (): int => -5);

        self::assertSame(0, $this->retention->days());
    }

    /**
     * The actual sweep: an old payload is cleared, a recent one is left
     * alone. This is the one test that would have caught a broken WHERE
     * clause -- the exact silent-corruption case the audit note describes.
     */
    public function testSweepClearsOldPayloadsAndLeavesRecentOnesAlone(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);

        $oldId   = $this->contractWith($partner, '11100000001');
        $recentId = $this->contractWith($partner, '11100000002');

        $this->setExtraction($oldId, '{"afm":"111111111"}', '100 days ago');
        $this->setExtraction($recentId, '{"afm":"222222222"}', '1 day ago');

        $cleared = $this->retention->sweep();

        self::assertSame(1, $cleared);
        self::assertNull($this->extractedJson($oldId));
        self::assertSame('{"afm":"222222222"}', $this->extractedJson($recentId));
    }

    public function testZeroDaysDisablesTheSweepEntirely(): void
    {
        $partner = $this->makeCrmUser(Roles::SELLER);
        $oldId   = $this->contractWith($partner, '11100000003');

        $this->setExtraction($oldId, '{"afm":"333333333"}', '365 days ago');

        add_filter('ecrm_extraction_retention_days', static fn (): int => 0);

        self::assertSame(0, $this->retention->sweep());
        self::assertSame('{"afm":"333333333"}', $this->extractedJson($oldId));
    }

    private function contractWith(int $partnerId, string $supply): int
    {
        $id = $this->contracts->create(
            ['status' => 'new', 'supply_number' => $supply, 'energy_type' => 'power'],
            UserScope::forSelf($partnerId)
        );

        self::assertGreaterThan(0, $id);

        return $id;
    }

    private function setExtraction(int $contractId, string $json, string $createdAt): void
    {
        global $wpdb;

        $updated = $wpdb->update(
            Tables::name(Tables::CONTRACTS),
            [
                'extracted_json' => $json,
                'created_at'     => gmdate('Y-m-d H:i:s', strtotime($createdAt)),
            ],
            ['id' => $contractId]
        );

        self::assertNotFalse($updated, 'Fixture failed to backdate the contract.');
    }

    private function extractedJson(int $contractId): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT extracted_json FROM %i WHERE id = %d',
                Tables::name(Tables::CONTRACTS),
                $contractId
            )
        );

        return $value === null ? null : (string) $value;
    }
}
