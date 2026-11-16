<?php

/**
 * (405) 0063: η ΔΕΗ μένει με τα προγράμματα που έστειλε ο ιδιοκτήτης (16/11).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Domain\Forms\DeiCatalogue;
use EnergyCRM\Persistence\Schema\Migrations\RefreshDeiCatalogueNovember2026;
use EnergyCRM\Persistence\Schema\Migrations\SeedDeiCatalogue2026;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class DeiCatalogueRefreshTest extends IntegrationTestCase
{
    public function testOnlyTheOfferedStayActiveAndTheNewOnesArrivePriced(): void
    {
        global $wpdb;

        $provider = $this->dei();
        $table    = Tables::name(Tables::PROGRAMS);

        (new SeedDeiCatalogue2026())->apply(new SchemaInspector());

        // Ένα φτιαγμένο με το χέρι, χωρίς κωδικό.
        $wpdb->insert($table, [
            'provider_id' => $provider, 'name' => 'Σταθερό Οικιακό', 'energy_type' => 'power',
            'category' => 'home', 'price_type' => 'fixed', 'active' => 1,
        ]);
        $manual = (int) $wpdb->insert_id;

        (new RefreshDeiCatalogueNovember2026())->apply(new SchemaInspector());
        // Δεύτερη φορά: τίποτα δεν διπλασιάζεται.
        (new RefreshDeiCatalogueNovember2026())->apply(new SchemaInspector());

        $active = $wpdb->get_col($wpdb->prepare(
            'SELECT code FROM %i WHERE provider_id = %d AND active = 1 ORDER BY sort_order',
            $table,
            $provider
        ));

        self::assertSame(array_keys(DeiCatalogue::offered()), $active);
        self::assertSame('0', (string) $wpdb->get_var($wpdb->prepare(
            'SELECT active FROM %i WHERE id = %d',
            $table,
            $manual
        )));

        $gas = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE provider_id = %d AND code = %s',
            $table,
            $provider,
            'dei_mybuilding_gascontrol'
        ), ARRAY_A);

        self::assertIsArray($gas);
        self::assertSame('gas', $gas['energy_type']);
        self::assertSame('communal', $gas['category']);
        self::assertSame(0.0799, (float) $gas['price_kwh']);
        self::assertSame(14.9, (float) $gas['fixed_charge']);
        self::assertSame(DeiCatalogue::AS_OF_NOVEMBER, substr((string) $gas['price_updated'], 0, 10));

        self::assertSame(1, (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE provider_id = %d AND code = %s',
            $table,
            $provider,
            'dei_g21'
        )));
    }

    private function dei(): int
    {
        global $wpdb;

        $table = Tables::name(Tables::PROVIDERS);
        $id    = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $table, 'dei'));

        if ($id > 0) {
            return $id;
        }

        $wpdb->insert($table, ['slug' => 'dei', 'name' => 'ΔΕΗ']);

        return (int) $wpdb->insert_id;
    }
}
