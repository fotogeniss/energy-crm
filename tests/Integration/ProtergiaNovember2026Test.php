<?php

/**
 * (371) Μετάβαση 0058: οι νέες εκδόσεις της Protergia παίρνουν τη θέση των
 * παλιών, μαζί με τους κανόνες προμήθειας και εγγύησης τους.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\Schema\Migrations\ReplaceProtergiaNovember2026;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class ProtergiaNovember2026Test extends IntegrationTestCase
{
    private const OLD = 'protergia_value_balance_12m';

    private const NEW = 'protergia_value_balance2_12m';

    public function testTheNewVersionTakesTheOldOnesPlaceWithItsRules(): void
    {
        global $wpdb;

        $provider = $this->protergia();
        $programs = Tables::name(Tables::PROGRAMS);

        $stale = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE provider_id = %d AND code = %s', $programs, $provider, self::NEW)
        );
        if ($stale > 0) {
            $wpdb->delete(Tables::name(Tables::COMMISSION_RULES), ['program_id' => $stale]);
            $wpdb->delete(Tables::name(Tables::GUARANTEE_RULES), ['program_id' => $stale]);
            $wpdb->delete($programs, ['id' => $stale]);
        }
        $old = $this->program($provider, self::OLD, 'variable');

        $wpdb->insert(Tables::name(Tables::COMMISSION_RULES), [
            'provider_id' => $provider,
            'program_id'  => $old,
            'amount'      => 33.00,
            'active'      => 1,
        ]);
        $wpdb->insert(Tables::name(Tables::GUARANTEE_RULES), [
            'provider_id' => $provider,
            'program_id'  => $old,
            'amount'      => 100.00,
            'active'      => 1,
        ]);

        (new ReplaceProtergiaNovember2026())->apply(new SchemaInspector());
        // Δεύτερη φορά: τίποτα διπλό.
        (new ReplaceProtergiaNovember2026())->apply(new SchemaInspector());

        /** @var array<string, mixed> $new */
        $new = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM %i WHERE provider_id = %d AND code = %s', $programs, $provider, self::NEW),
            ARRAY_A
        );

        self::assertSame('Protergia Value Balance 2.0 12M', $new['name']);
        self::assertSame(1, (int) $new['active']);
        // Το χρώμα που είχε δώσει ο διαχειριστής στο παλιό μένει.
        self::assertSame('variable', $new['price_type']);
        self::assertSame('home', $new['category']);
        self::assertEqualsWithDelta(14.90, (float) $new['fixed_charge'], 0.001);
        self::assertSame('2026-11-12', $new['price_updated']);
        self::assertSame('[240,240,240,180,180,180,120,120,120,60,60,0]', $new['exit_fees']);

        self::assertSame(0, (int) $wpdb->get_var(
            $wpdb->prepare('SELECT active FROM %i WHERE id = %d', $programs, $old)
        ));

        $newId = (int) $new['id'];
        foreach ([Tables::COMMISSION_RULES, Tables::GUARANTEE_RULES] as $rules) {
            self::assertSame($this->amounts($rules, $old), $this->amounts($rules, $newId), $rules);
        }
        self::assertContains('33.00', $this->amounts(Tables::COMMISSION_RULES, $newId));
        self::assertContains('100.00', $this->amounts(Tables::GUARANTEE_RULES, $newId));
    }

    public function testGasSure2IsWithdrawnWithoutASuccessor(): void
    {
        global $wpdb;

        $provider = $this->protergia();
        $id       = $this->program($provider, 'protergia_gas_sure2', 'fixed');

        (new ReplaceProtergiaNovember2026())->apply(new SchemaInspector());

        self::assertSame(0, (int) $wpdb->get_var(
            $wpdb->prepare('SELECT active FROM %i WHERE id = %d', Tables::name(Tables::PROGRAMS), $id)
        ));
    }

    /** @return list<string> */
    private function amounts(string $table, int $programId): array
    {
        global $wpdb;

        /** @var list<string> $out */
        $out = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT amount FROM %i WHERE program_id = %d AND active = 1 ORDER BY amount',
                Tables::name($table),
                $programId
            )
        );

        return $out;
    }

    private function protergia(): int
    {
        global $wpdb;

        $table = Tables::name(Tables::PROVIDERS);
        $id    = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $table, 'protergia'));

        if ($id > 0) {
            return $id;
        }

        $wpdb->insert($table, ['slug' => 'protergia', 'name' => 'Protergia']);

        return (int) $wpdb->insert_id;
    }

    /** Το πρόγραμμα, ενεργό και με το χρώμα που δίνεται· φτιάχνεται αν λείπει. */
    private function program(int $provider, string $code, string $colour): int
    {
        global $wpdb;

        $table = Tables::name(Tables::PROGRAMS);
        $id    = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE provider_id = %d AND code = %s', $table, $provider, $code)
        );

        if ($id > 0) {
            $wpdb->update($table, ['active' => 1, 'price_type' => $colour], ['id' => $id]);

            return $id;
        }

        $wpdb->insert($table, [
            'provider_id' => $provider,
            'name'        => $code,
            'code'        => $code,
            'energy_type' => str_contains($code, 'gas') ? 'gas' : 'power',
            'category'    => 'home',
            'price_type'  => $colour,
            'active'      => 1,
        ]);

        return (int) $wpdb->insert_id;
    }
}
