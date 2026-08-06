<?php

/**
 * Corrects which energy each provider actually sells.
 *
 * The seed data claimed Orizon sells electricity and gas, and that ΔΕΗ and NRG
 * sell mobile telephony. None of it is true: Orizon is a mobile operator — all
 * three of its forms describe a line — and the energy suppliers do not offer
 * mobile at all.
 *
 * It matters beyond tidiness. The template is chosen from provider and energy
 * type together, so an agent who picked Orizon and left the default
 * "Ηλεκτρισμός" chip selected produced a mobile application filled in from a
 * meter, or no form at all.
 *
 * The seeder only ever inserts, never corrects, so the wrong values survive on
 * every site that has been running. Hence a migration rather than an edit to
 * the defaults alone.
 *
 * Providers the operator has edited by hand are left alone: this fixes what we
 * shipped wrong, it does not overrule someone who knows their own market.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class FixProviderEnergyTypes implements Migration
{
    /** slug => [what we shipped, what it should be]. */
    private const CORRECTIONS = [
        'orizon' => ['power,gas,mobile', 'mobile'],
        'dei'    => ['power,gas,mobile', 'power,gas'],
        'nrg'    => ['power,gas,mobile', 'power,gas'],
    ];

    public function id(): string
    {
        return '0008_fix_provider_energy_types';
    }

    public function description(): string
    {
        return 'Η Orizon είναι κινητή· η ΔΕΗ και η NRG δεν είναι';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::PROVIDERS);

        if (! $schema->hasTable($table)) {
            return;
        }

        foreach (self::CORRECTIONS as $slug => [$shipped, $correct]) {
            // Matching on the old value is the whole safety net: a row that no
            // longer says what we shipped has been edited, and stays as it is.
            $wpdb->update(
                $table,
                ['energy_types' => $correct],
                ['slug' => $slug, 'energy_types' => $shipped]
            );
        }
    }
}
