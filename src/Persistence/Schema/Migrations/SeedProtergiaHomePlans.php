<?php

/**
 * Gives Protergia its four residential tariffs, and retires the generic
 * starter program that stood in for them.
 *
 * Protergia replaced its single residential application (Picasso 2.0) with one
 * per tariff. Which sheet to print is now decided by the program on the
 * contract, so a provider whose only program is the codeless «Σταθερό Οικιακό»
 * that `ECRM_Providers::seed()` shipped has no way to reach any of the four —
 * the agent picks the one option there is and the CRM falls back to the retired
 * Picasso form.
 *
 * Only our own seed data is retired, and only when untouched: a starter that
 * already has a `code`, or that somebody renamed, is left alone — it stopped
 * being ours the moment an operator edited it (the rule `SeedOrizonPlans` and
 * `FixProviderEnergyTypes` already follow). Retiring means `active = 0`, never
 * DELETE: contracts already sold on it point at its id and must keep printing
 * what they were sold on.
 *
 * Re-running is safe: the insert step skips any provider/code pair that exists.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\ProtergiaHomePlans;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SeedProtergiaHomePlans implements Migration
{
    /** The placeholder name ECRM_Providers::seed() shipped for electricity. */
    private const RETIRED_STARTER = 'Σταθερό Οικιακό';

    public function id(): string
    {
        return '0014_seed_protergia_home_plans';
    }

    public function description(): string
    {
        return 'Τα 4 οικιακά τιμολόγια Protergia (Sure 12/18, Lite 2.0, Bright)'
            . ' με code, στη θέση του γενικού προγράμματος';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($providers) || ! $schema->hasTable($programs)) {
            return;
        }

        if (! $schema->hasColumn($programs, 'code')) {
            // AddProgramCodeColumn (0012) runs earlier in MigrationList, but a
            // run against a half-upgraded schema must not insert rows that
            // nothing can later find by code.
            return;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM %i WHERE slug = %s", [$providers, 'protergia'])
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        if ($providerId <= 0) {
            return;
        }

        $this->addPlans($programs, $providerId);
        $this->retireStarter($programs, $providerId);
    }

    private function addPlans(string $programs, int $providerId): void
    {
        global $wpdb;

        $sort = 0;

        foreach (ProtergiaHomePlans::all() as $code => $plan) {
            $sort++;

            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE provider_id = %d AND code = %s',
                    [$programs, $providerId, $code]
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

            if ($exists > 0) {
                continue;
            }

            $wpdb->insert($programs, [
                'provider_id'  => $providerId,
                'name'         => $plan['label'],
                'code'         => $code,
                'energy_type'  => 'power',
                'category'     => 'home',
                'price_type'   => $plan['priceType'],
                'fixed_charge' => $plan['fixedCharge'],
                'price_kwh'    => $plan['priceKwh'],
                'active'       => 1,
                'sort_order'   => $sort,
            ]);
        }
    }

    private function retireStarter(string $programs, int $providerId): void
    {
        global $wpdb;

        $wpdb->update(
            $programs,
            ['active' => 0],
            [
                'provider_id' => $providerId,
                'name'        => self::RETIRED_STARTER,
                'energy_type' => 'power',
                'code'        => null,
            ]
        );
    }
}
