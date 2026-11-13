<?php

/**
 * (380) ΔΕΗ: τα 13 προγράμματα ρεύματος του `DeiCatalogue`, με τις τιμές των
 * εντύπων 11.26 (`DeiPrices2026`).
 *
 * Μόνο όσα λείπουν: ένα πρόγραμμα ΔΕΗ με τον ίδιο κωδικό, που έφτιαξε ήδη ο
 * διαχειριστής, μένει όπως είναι. Τιμή γράφεται μόνο όπου δεν υπάρχει
 * ημερομηνία τιμής -- ό,τι έγραψε ο διαχειριστής στη σελίδα Παρόχων μένει.
 * Τα προγράμματα ΔΕΗ χωρίς κωδικό (π.χ. το παλιό «Σταθερό Οικιακό») δεν
 * αγγίζονται: μπορεί να τα κρατούν αιτήσεις.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\DeiCatalogue;
use EnergyCRM\Domain\Pricing\DeiPrices2026;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SeedDeiCatalogue2026 implements Migration
{
    public function id(): string
    {
        return '0062_seed_dei_catalogue_2026';
    }

    public function description(): string
    {
        return 'ΔΕΗ: 13 προγράμματα ρεύματος με τις τιμές των εντύπων 11.26 (380)';
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
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, DeiCatalogue::SLUG)
        );

        if ($providerId <= 0) {
            return;
        }

        foreach (DeiCatalogue::all() as $code => $plan) {
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
                'price_type'  => $plan['priceType'],
                'active'      => 1,
                'sort_order'  => $plan['sort'],
            ]);
        }

        foreach (['price_kwh', 'fixed_charge', 'pricing', 'price_updated'] as $column) {
            if (! $schema->hasColumn($programs, $column)) {
                return;
            }
        }

        foreach (DeiPrices2026::all() as $code => $price) {
            // update() γράφει NULL για null τιμές και `IS NULL` για null στο WHERE.
            $wpdb->update(
                $programs,
                [
                    'price_kwh'     => $price['price_kwh'],
                    'fixed_charge'  => $price['fixed_charge'],
                    'pricing'       => (string) json_encode($price['pricing'], JSON_UNESCAPED_UNICODE),
                    'price_updated' => DeiPrices2026::AS_OF,
                ],
                ['provider_id' => $providerId, 'code' => $code, 'price_updated' => null]
            );
        }
    }
}
