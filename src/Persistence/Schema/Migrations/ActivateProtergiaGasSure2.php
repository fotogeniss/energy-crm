<?php

/**
 * (337) Value Gas Sure 2.0: ήρθε το έντυπο (Protergia, 30/10/2026).
 *
 * Η 0045 το είχε κάνει ανενεργό ώσπου να έρθει. Τώρα ξαναενεργοποιείται και
 * παίρνει την τιμή και τη ρήτρα της αίτησης (ProtergiaPrices2026). Τιμή που
 * έγραψε ήδη ο διαχειριστής (price_updated) και ρήτρα που υπάρχει δεν
 * αλλάζουν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Pricing\ProtergiaPrices2026;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class ActivateProtergiaGasSure2 implements Migration
{
    private const CODE = 'protergia_gas_sure2';

    public function id(): string
    {
        return '0051_activate_protergia_gas_sure2';
    }

    public function description(): string
    {
        return 'Protergia Value Gas Sure 2.0: ενεργό, με τιμή και ρήτρα από το έντυπο 10/2026 (337)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($providers) || ! $schema->hasTable($programs)) {
            return;
        }

        foreach (['code', 'pricing', 'price_updated', 'exit_fees'] as $column) {
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

        $where = ['provider_id' => $providerId, 'code' => self::CODE];
        $price = ProtergiaPrices2026::all()[self::CODE];

        $wpdb->update($programs, ['active' => 1], $where);

        $wpdb->update(
            $programs,
            [
                'price_kwh'     => $price['price_kwh'],
                'fixed_charge'  => $price['fixed_charge'],
                'pricing'       => (string) json_encode($price['pricing'], JSON_UNESCAPED_UNICODE),
                'price_updated' => '2026-10-30',
            ],
            $where + ['price_updated' => null]
        );

        $wpdb->update(
            $programs,
            ['exit_fees' => (string) json_encode(ProtergiaPrices2026::exitFees()[self::CODE])],
            $where + ['exit_fees' => null]
        );
    }
}
