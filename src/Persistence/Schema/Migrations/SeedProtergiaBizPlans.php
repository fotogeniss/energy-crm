<?php

/**
 * Δίνει στην Protergia τα τρία επαγγελματικά της τιμολόγια ρεύματος.
 *
 * Μέχρι σήμερα η Protergia δεν είχε ΚΑΝΕΝΑ πρόγραμμα με `category = business`:
 * ο πωλητής που πατούσε «Επαγγελματικό» έβλεπε κενό dropdown, και κάθε
 * επαγγελματική σύμβαση τύπωνε το ενιαίο `protergia_he_biz`. Τα τρία μπαίνουν
 * με `code`, και ο κωδικός είναι αυτός που διαλέγει το έντυπο
 * (`ECRM_FormFill::template_key()` → `ProtergiaBizPlans::templateKey()`).
 *
 * Σε αντίθεση με το `SeedProtergiaHomePlans` δεν αποσύρεται τίποτα: δεν
 * υπήρξε ποτέ επαγγελματικό starter για να πάρουν τη θέση του.
 *
 * Η επανεκτέλεση είναι ασφαλής: το βήμα εισαγωγής προσπερνά κάθε ζεύγος
 * provider/code που υπάρχει ήδη (και ο unique index του `DedupeProgramsByCode`
 * το εγγυάται και από κάτω).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\ProtergiaBizPlans;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SeedProtergiaBizPlans implements Migration
{
    public function id(): string
    {
        return '0038_seed_protergia_biz_plans';
    }

    public function description(): string
    {
        return 'Τα 3 επαγγελματικά τιμολόγια Protergia (Γ21 Sure, Simple, Seasonal)'
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
            // Ίδια προφύλαξη με το SeedProtergiaHomePlans: γραμμές χωρίς code
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

        foreach (ProtergiaBizPlans::all() as $code => $plan) {
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
                'category'     => 'business',
                'price_type'   => $plan['priceType'],
                'fixed_charge' => $plan['fixedCharge'],
                'price_kwh'    => $plan['priceKwh'],
                'active'       => 1,
                'sort_order'   => $sort,
            ]);
        }
    }
}
