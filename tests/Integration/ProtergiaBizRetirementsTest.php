<?php

/**
 * (374) Μετάβαση 0061: τέλος τα επαγγελματικά Value Seasonal 2026 (Ε1) και
 * Value Sure 2.0 (Ε3). Απενεργοποιούνται, δεν σβήνονται.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\Schema\Migrations\RetireProtergiaBizNovember2026;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class ProtergiaBizRetirementsTest extends IntegrationTestCase
{
    public function testTheTwoProgramsAreDeactivatedAndTheRestStay(): void
    {
        $provider = $this->protergia();
        $seasonal = $this->program($provider, 'protergia_epag_seasonal');
        $sure     = $this->program($provider, 'protergia_epag3_sure12m_2');
        $standard = $this->program($provider, 'protergia_epag3_standard2');

        (new RetireProtergiaBizNovember2026())->apply(new SchemaInspector());
        // Δεύτερη φορά: τίποτα δεν αλλάζει.
        (new RetireProtergiaBizNovember2026())->apply(new SchemaInspector());

        self::assertSame(0, $this->activeOf($seasonal), 'Value Seasonal 2026 (Ε1)');
        self::assertSame(0, $this->activeOf($sure), 'Value Sure 2.0 (Ε3)');
        self::assertSame(1, $this->activeOf($standard), 'Ε3 Value Standard 2.0 μένει');
    }

    /** Δεν σβήνεται: οι παλιές αιτήσεις κρατάνε το πρόγραμμά τους. */
    public function testTheRowsStayForOldApplications(): void
    {
        global $wpdb;

        $provider = $this->protergia();
        $sure     = $this->program($provider, 'protergia_epag3_sure12m_2');

        (new RetireProtergiaBizNovember2026())->apply(new SchemaInspector());

        self::assertSame('protergia_epag3_sure12m_2', (string) $wpdb->get_var(
            $wpdb->prepare('SELECT code FROM %i WHERE id = %d', Tables::name(Tables::PROGRAMS), $sure)
        ));
    }

    private function activeOf(int $programId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT active FROM %i WHERE id = %d', Tables::name(Tables::PROGRAMS), $programId)
        );
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

    /** Το πρόγραμμα, ενεργό· φτιάχνεται αν λείπει. */
    private function program(int $provider, string $code): int
    {
        global $wpdb;

        $table = Tables::name(Tables::PROGRAMS);
        $id    = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE provider_id = %d AND code = %s', $table, $provider, $code)
        );

        if ($id > 0) {
            $wpdb->update($table, ['active' => 1], ['id' => $id]);

            return $id;
        }

        $wpdb->insert($table, [
            'provider_id' => $provider,
            'name'        => $code,
            'code'        => $code,
            'energy_type' => 'power',
            'category'    => 'business',
            'active'      => 1,
        ]);

        return (int) $wpdb->insert_id;
    }
}
