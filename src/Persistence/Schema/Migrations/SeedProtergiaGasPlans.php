<?php

/**
 * Δίνει στην Protergia τα δύο τιμολόγια φυσικού αερίου της (Value Gas Sure,
 * Single Value), με `code` -- ο κωδικός διαλέγει το έντυπο
 * (`ECRM_FormFill::template_key()` → `ProtergiaGasPlans::templateKey()`).
 *
 * Δεν αποσύρεται τίποτα: ό,τι πρόγραμμα αερίου υπάρχει ήδη μένει, και οι
 * συμβάσεις του τυπώνουν το `protergia_fa` όπως πριν.
 *
 * Η επανεκτέλεση είναι ασφαλής: το βήμα εισαγωγής προσπερνά κάθε ζεύγος
 * provider/code που υπάρχει ήδη (και ο unique index του `DedupeProgramsByCode`
 * το εγγυάται και από κάτω).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\ProtergiaGasPlans;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SeedProtergiaGasPlans implements Migration
{
    public function id(): string
    {
        return '0039_seed_protergia_gas_plans';
    }

    public function description(): string
    {
        return 'Τα 2 τιμολόγια φυσικού αερίου Protergia (Value Gas Sure, Single Value)'
            . ' με code, ένα έντυπο το καθένα';
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
            // Ίδια προφύλαξη με το SeedProtergiaBizPlans: γραμμές χωρίς code
            // δεν τις βρίσκει μετά κανείς.
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

        $sort = 0;

        foreach (ProtergiaGasPlans::all() as $code => $plan) {
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
                'energy_type'  => 'gas',
                'category'     => 'home',
                'price_type'   => $plan['priceType'],
                'fixed_charge' => $plan['fixedCharge'],
                'price_kwh'    => $plan['priceKwh'],
                'active'       => 1,
                'sort_order'   => $sort,
            ]);
        }
    }
}
