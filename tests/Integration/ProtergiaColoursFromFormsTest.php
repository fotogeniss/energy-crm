<?php

/**
 * (373) Μετάβαση 0060: χρώμα από το έντυπο για Standard 2.0, Student Plan
 * και Value Power 2.0, μόνο όπου λείπει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Persistence\Schema\Migrations\SetProtergiaColoursFromForms;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class ProtergiaColoursFromFormsTest extends IntegrationTestCase
{
    public function testProgramsWithoutAColourGetTheOneOnTheirForm(): void
    {
        $provider = $this->protergia();
        $standard = $this->program($provider, 'protergia_value_standard2', '');
        $student  = $this->program($provider, 'protergia_student_plan', '');
        $power    = $this->program($provider, 'protergia_epag2_power2', '');

        (new SetProtergiaColoursFromForms())->apply(new SchemaInspector());

        self::assertSame('variable', $this->colourOf($standard));
        self::assertSame('variable', $this->colourOf($student));
        self::assertSame('fixed', $this->colourOf($power));
    }

    /** Ό,τι έδωσε ο διαχειριστής από τη σελίδα παρόχων μένει. */
    public function testAColourChosenByTheAdministratorStays(): void
    {
        $provider = $this->protergia();
        $student  = $this->program($provider, 'protergia_student_plan', 'special');

        (new SetProtergiaColoursFromForms())->apply(new SchemaInspector());

        self::assertSame('special', $this->colourOf($student));
    }

    /** Το Power+Gas δεν έχει δικό του έντυπο: μένει χωρίς χρώμα (απόφαση 12/11). */
    public function testPowerAndGasStaysWithoutAColour(): void
    {
        $provider = $this->protergia();
        $combo    = $this->program($provider, 'protergia_power_gas_he', '');

        (new SetProtergiaColoursFromForms())->apply(new SchemaInspector());

        self::assertSame('', $this->colourOf($combo));
    }

    private function colourOf(int $programId): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare('SELECT price_type FROM %i WHERE id = %d', Tables::name(Tables::PROGRAMS), $programId)
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
            'energy_type' => 'power',
            'category'    => 'home',
            'price_type'  => $colour,
            'active'      => 1,
        ]);

        return (int) $wpdb->insert_id;
    }
}
