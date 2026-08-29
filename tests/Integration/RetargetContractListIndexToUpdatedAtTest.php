<?php

/**
 * Το composite index της λίστας συμβάσεων στοχεύει πλέον updated_at.
 *
 * Δεν είναι test κόστους -- αυτό το μέτρησε το tools/measure-contract-list.php
 * με πραγματικά EXPLAIN plans (docs/CHANGELOG.md, το entry του §2.2). Αυτό
 * εδώ είναι το ίχνος αντίστροφης παλινδρόμησης: αν κάποιος ξαναφτιάξει το
 * παλιό index πάνω σε created_at, ή αν το migration σπάσει σιωπηλά και μείνει
 * χωρίς κανένα από τα δύο, αυτό το test το πιάνει χωρίς να χρειαστεί να
 * ξαναστηθούν συνθετικά δεδομένα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class RetargetContractListIndexToUpdatedAtTest extends IntegrationTestCase
{
    public function testTheOldIndexOnCreatedAtIsGone(): void
    {
        $schema = new SchemaInspector();

        self::assertFalse(
            $schema->hasIndex(Tables::name(Tables::CONTRACTS), 'partner_status_created'),
            'Το παλιό index (partner_user_id, status, created_at) έπρεπε να έχει αφαιρεθεί.'
        );
    }

    public function testTheNewIndexOnUpdatedAtExists(): void
    {
        $schema = new SchemaInspector();

        self::assertTrue(
            $schema->hasIndex(Tables::name(Tables::CONTRACTS), 'partner_status_updated'),
            'Το index (partner_user_id, status, updated_at) που όντως ταξινομεί η λίστα λείπει.'
        );
    }
}
