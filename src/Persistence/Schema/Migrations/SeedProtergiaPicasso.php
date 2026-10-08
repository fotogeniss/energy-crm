<?php

/**
 * Τα Picasso S/M/L της Protergia, 9 οικιακά και 9 επαγγελματικά (08/10/2026).
 *
 * Ένα πρόγραμμα ανά κωδικό, μόνο αν δεν υπάρχει. Τι και γιατί:
 * `ProtergiaPicassoPlans`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\ProtergiaPicassoPlans;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SeedProtergiaPicasso implements Migration
{
    public function id(): string
    {
        return '0042_seed_protergia_picasso_sml';
    }

    public function description(): string
    {
        return 'Protergia: Picasso S/M/L, 9 οικιακά και 9 επαγγελματικά προγράμματα';
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
            return;
        }

        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, 'protergia')
        );

        if ($providerId <= 0) {
            return;
        }

        foreach (ProtergiaPicassoPlans::all() as $code => $plan) {
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE provider_id = %d AND code = %s',
                    $programs,
                    $providerId,
                    $code
                )
            );

            if ($exists > 0) {
                continue;
            }

            $wpdb->insert($programs, [
                'provider_id' => $providerId,
                'name'        => $plan['label'],
                'code'        => $code,
                'energy_type' => 'power',
                'category'    => $plan['category'],
                'price_type'  => '',
                'active'      => 1,
                'sort_order'  => $plan['sort'],
            ]);
        }
    }
}
