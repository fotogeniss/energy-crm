<?php

/**
 * (319) Τα 13 επαγγελματικά ρεύματος και τα 2 αερίου της Protergia που
 * έλειπαν, και απενεργοποίηση του «Γ21 Value Sure 12 Μήνες 3.0» που δεν
 * πουλιέται πια. Τι και γιατί: `ProtergiaBizCatalogue`.
 *
 * Ένα ανά κωδικό, μόνο αν δεν υπάρχει: ένα πρόγραμμα που ο διαχειριστής
 * μετονόμασε ή απενεργοποίησε από τη σελίδα παρόχων δεν ξαναγράφεται.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\ProtergiaBizCatalogue;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SeedProtergiaBizCatalogue2026 implements Migration
{
    public function id(): string
    {
        return '0044_seed_protergia_biz_catalogue_2026';
    }

    public function description(): string
    {
        return 'Protergia: 13 επαγγελματικά ρεύματος, 2 αερίου, απενεργοποίηση Γ21 Value Sure 12 3.0';
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

        foreach (ProtergiaBizCatalogue::all() as $code => $plan) {
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
                'energy_type' => $plan['energy'],
                'category'    => 'business',
                'price_type'  => $plan['priceType'],
                'active'      => 1,
                'sort_order'  => $plan['sort'],
            ]);
        }

        foreach (ProtergiaBizCatalogue::RETIRED as $code) {
            $wpdb->update($programs, ['active' => 0], ['provider_id' => $providerId, 'code' => $code]);
        }
    }
}
