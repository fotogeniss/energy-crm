<?php

/**
 * Τα προγράμματα Protergia της λίστας του ιδιοκτήτη (07/10/2026).
 *
 * Προσθέτει τα νέα (ένα ανά κωδικό, μόνο αν δεν υπάρχει), βάζει το Value
 * Lite 2.0 στη θέση του, και απενεργοποιεί όσα δεν είναι πια στη λίστα. Τι
 * και γιατί: `ProtergiaCatalogue`.
 *
 * Τα επαγγελματικά (`ProtergiaBizPlans`) δεν αγγίζονται: η λίστα αφορούσε
 * οικιακά ρεύματος και φυσικό αέριο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\ProtergiaCatalogue;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SeedProtergiaCatalogue2026 implements Migration
{
    public function id(): string
    {
        return '0041_seed_protergia_catalogue_2026';
    }

    public function description(): string
    {
        return 'Protergia: 13 νέα προγράμματα (ρεύμα και αέριο), απενεργοποίηση όσων δεν πουλιούνται πια';
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

        foreach (ProtergiaCatalogue::added() as $code => $plan) {
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
                'category'    => 'home',
                'price_type'  => $plan['priceType'],
                'active'      => 1,
                'sort_order'  => $plan['sort'],
            ]);
        }

        foreach (ProtergiaCatalogue::KEPT as $code => $sort) {
            $wpdb->update(
                $programs,
                ['sort_order' => $sort, 'active' => 1],
                ['provider_id' => $providerId, 'code' => $code]
            );
        }

        foreach (ProtergiaCatalogue::RETIRED as $code) {
            $wpdb->update($programs, ['active' => 0], ['provider_id' => $providerId, 'code' => $code]);
        }
    }
}
