<?php

/**
 * (328) Οι σταθερές τιμές Protergia στα προγράμματα του καταλόγου 2026.
 *
 * Τα προγράμματα του καταλόγου (07/10) μπήκαν χωρίς τιμή, οπότε η πρόταση
 * από λογαριασμό δεν θα είχε τι να συγκρίνει. Οι τιμές είναι αυτές που
 * τυπώνει το έντυπο (Domain\Pricing\ProtergiaPrices2026).
 *
 * Μόνο σε προγράμματα που δεν έχουν ακόμα ημερομηνία τιμής: αν ο διαχειριστής
 * έγραψε τιμή από τη σελίδα Παρόχων, η δική του μένει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Pricing\ProtergiaPrices2026;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SeedProtergiaPrices2026 implements Migration
{
    public function id(): string
    {
        return '0048_seed_protergia_prices_2026';
    }

    public function description(): string
    {
        return 'Protergia: τιμές σταθερών, κλιμακωτών και Picasso από τα έντυπα 10/2026 (328)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($providers) || ! $schema->hasTable($programs)) {
            return;
        }

        foreach (['code', 'pricing', 'price_updated'] as $column) {
            if (! $schema->hasColumn($programs, $column)) {
                return;
            }
        }

        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, 'protergia')
        );

        if ($providerId <= 0) {
            return;
        }

        foreach (ProtergiaPrices2026::all() as $code => $price) {
            // update() γράφει NULL για null τιμές και `IS NULL` για null στο WHERE.
            $wpdb->update(
                $programs,
                [
                    'price_kwh'     => $price['price_kwh'],
                    'fixed_charge'  => $price['fixed_charge'],
                    'pricing'       => $price['pricing'] === null
                        ? null
                        : (string) json_encode($price['pricing'], JSON_UNESCAPED_UNICODE),
                    'price_updated' => ProtergiaPrices2026::AS_OF,
                ],
                ['provider_id' => $providerId, 'code' => $code, 'price_updated' => null]
            );
        }
    }
}
