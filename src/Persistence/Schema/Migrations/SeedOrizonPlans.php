<?php

/**
 * Replaces the two generic mobile placeholder programs with the four real
 * Orizon plans, each carrying the `code` that ECRM_FormFill needs to print a
 * price.
 *
 * `SeedMobilePrograms` (0009) gave every mobile-selling provider two starter
 * programs — "Κινητή — Βασικό" and the Family variant — so the dropdown was
 * never empty on an existing install. They were never real plans: Orizon
 * prices four specific ones (5GB / 10GB+5GB / 40GB / unlimited), and neither
 * starter can be filled in on paper because it has no entry in `MobilePlans`.
 * A contract sold against a starter program prints with no plan ticked and no
 * price — the same silent gap this whole change exists to close.
 *
 * Only what we shipped is retired: a starter program that already has a
 * `code`, or has been renamed, is left alone — this corrects our own seed
 * data, not something an operator configured on purpose (same rule
 * `FixProviderEnergyTypes` follows). Re-running is safe: the insert step
 * skips any provider/code pair that already exists.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\MobilePlans;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SeedOrizonPlans implements Migration
{
    /** The two placeholder names 0009 shipped, retired in favour of real plans. */
    private const RETIRED_STARTERS = [
        'Κινητή — Βασικό',
        'Κινητή — Συνδυαστική Προσφορά (Family)',
    ];

    public function id(): string
    {
        return '0013_seed_orizon_plans';
    }

    public function description(): string
    {
        return 'Τα 4 πραγματικά πλάνα Orizon (με code) στη θέση των γενικών placeholder προγραμμάτων';
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
            // AddProgramCodeColumn (0012) runs first in MigrationList, but
            // guard anyway: a run against a schema mid-upgrade must not insert
            // rows a later step will not be able to find by code.
            return;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $providerIds = $wpdb->get_col(
            $wpdb->prepare('SELECT p.id FROM %i p WHERE p.energy_types LIKE %s', [$providers, '%mobile%'])
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        foreach ((array) $providerIds as $providerId) {
            $this->retireStarters($programs, (int) $providerId);
            $this->addRealPlans($programs, (int) $providerId);
        }
    }

    private function retireStarters(string $programs, int $providerId): void
    {
        global $wpdb;

        foreach (self::RETIRED_STARTERS as $name) {
            $wpdb->update(
                $programs,
                ['active' => 0],
                [
                    'provider_id' => $providerId,
                    'name'        => $name,
                    'energy_type' => 'mobile',
                    'code'        => null,
                ]
            );
        }
    }

    private function addRealPlans(string $programs, int $providerId): void
    {
        global $wpdb;

        foreach (MobilePlans::options() as $code => $label) {
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
                'provider_id' => $providerId,
                'name'        => $label,
                'code'        => $code,
                'energy_type' => 'mobile',
                'category'    => 'home',
                'price_type'  => 'fixed',
                'active'      => 1,
                'sort_order'  => 0,
            ]);
        }
    }
}
