<?php

/**
 * (380) Μετάβαση 0062: τα 13 προγράμματα ρεύματος της ΔΕΗ με τις τιμές των
 * εντύπων 11.26. Μόνο όσα λείπουν· ό,τι έγραψε ο διαχειριστής μένει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Domain\Forms\DeiCatalogue;
use EnergyCRM\Persistence\Schema\Migrations\SeedDeiCatalogue2026;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class DeiCatalogueSeedTest extends IntegrationTestCase
{
    public function testEveryProgramOnceWithItsColourAndCategory(): void
    {
        global $wpdb;

        $provider = $this->dei();

        (new SeedDeiCatalogue2026())->apply(new SchemaInspector());
        // Δεύτερη φορά: τίποτα δεν διπλασιάζεται.
        (new SeedDeiCatalogue2026())->apply(new SchemaInspector());

        foreach (DeiCatalogue::all() as $code => $plan) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT category, price_type, active FROM %i WHERE provider_id = %d AND code = %s',
                    Tables::name(Tables::PROGRAMS),
                    $provider,
                    $code
                ),
                ARRAY_A
            );

            self::assertCount(1, $rows, $code);
            self::assertSame($plan['category'], $rows[0]['category'], $code);
            self::assertSame($plan['priceType'], $rows[0]['price_type'], $code);
            self::assertSame('1', (string) $rows[0]['active'], $code);
        }
    }

    public function testThePricesOfTheFormsGoWhereThereIsNone(): void
    {
        global $wpdb;

        $provider = $this->dei();
        $table    = Tables::name(Tables::PROGRAMS);

        (new SeedDeiCatalogue2026())->apply(new SchemaInspector());

        $wpdb->update(
            $table,
            ['price_updated' => null, 'pricing' => null, 'price_kwh' => null],
            ['provider_id' => $provider, 'code' => 'dei_myhome_maxima']
        );
        // Τιμή του διαχειριστή: μένει.
        $wpdb->update(
            $table,
            ['price_updated' => '2026-11-20', 'price_kwh' => 0.111, 'pricing' => null],
            ['provider_id' => $provider, 'code' => 'dei_myhome_enter']
        );

        (new SeedDeiCatalogue2026())->apply(new SchemaInspector());

        $maxima  = $this->row($provider, 'dei_myhome_maxima');
        $pricing = json_decode((string) $maxima['pricing'], true);

        self::assertSame('tiered', $pricing['kind']);
        self::assertSame([[600, 0.175], [null, 0.163]], $pricing['tiers']);
        self::assertSame(DeiCatalogue::AS_OF, substr((string) $maxima['price_updated'], 0, 10));

        $enter = $this->row($provider, 'dei_myhome_enter');

        self::assertSame(0.111, (float) $enter['price_kwh']);
        self::assertSame('2026-11-20', substr((string) $enter['price_updated'], 0, 10));
    }

    /** @return array<string, mixed> */
    private function row(int $provider, string $code): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE provider_id = %d AND code = %s',
                Tables::name(Tables::PROGRAMS),
                $provider,
                $code
            ),
            ARRAY_A
        );

        self::assertIsArray($row, $code);

        return $row;
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
