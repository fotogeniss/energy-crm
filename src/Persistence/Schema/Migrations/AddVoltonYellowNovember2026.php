<?php

/**
 * (456) Τα κίτρινα προγράμματα της Volton από τους Ειδικούς Όρους (26/11/2026).
 *
 * Δύο νέα προγράμματα που η Volton πουλά και το CRM δεν είχε: «Volton Yellow
 * Protect Oct '26» (οικιακό) και «Volton Yellow Standard Business Γ21». Και το
 * πάγιο των Yellow Simple Γ22/Γ23, που έμενε κενό επειδή δεν είχε μετρηθεί.
 *
 * Το `SeedVoltonPlans` (0028) έχει ήδη τρέξει, οπότε δεν ξαναδιαβάζει τον
 * κατάλογο. Εδώ: ό,τι λείπει μπαίνει, και πάγιο μπαίνει μόνο όπου είναι κενό
 * (δεν πατάμε ό,τι άλλαξε ο διαχειριστής).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\VoltonPlans;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddVoltonYellowNovember2026 implements Migration
{
    private const NEW = ['volton_yellow_protect_oct26', 'volton_yellow_standard_g21'];

    private const CHARGES = ['volton_yellow_simple_g22', 'volton_yellow_simple_g23'];

    public function id(): string
    {
        return '0067_add_volton_yellow_november_2026';
    }

    public function description(): string
    {
        return 'Volton: Yellow Protect Oct \'26, Yellow Standard Business Γ21, πάγιο Yellow Simple Γ22/Γ23';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (
            ! $schema->hasTable($providers)
            || ! $schema->hasTable($programs)
            || ! $schema->hasColumn($programs, 'code')
        ) {
            return;
        }

        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, 'volton')
        );

        if ($providerId <= 0) {
            return;
        }

        $plans = VoltonPlans::all();
        $sort  = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM %i WHERE provider_id = %d', $programs, $providerId)
        );

        foreach (self::NEW as $code) {
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE provider_id = %d AND code = %s',
                    $programs,
                    $providerId,
                    $code
                )
            );

            if ($exists > 0 || ! isset($plans[$code])) {
                continue;
            }

            $plan = $plans[$code];
            $wpdb->insert($programs, [
                'provider_id'  => $providerId,
                'name'         => $plan['label'],
                'code'         => $code,
                'energy_type'  => $plan['energyType'],
                'category'     => $plan['category'],
                'price_type'   => $plan['priceType'],
                'fixed_charge' => $plan['fixedCharge'],
                'price_kwh'    => $plan['priceKwh'],
                'active'       => 1,
                'sort_order'   => ++$sort,
            ]);
        }

        foreach (self::CHARGES as $code) {
            $charge = $plans[$code]['fixedCharge'] ?? null;

            if ($charge === null) {
                continue;
            }

            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET fixed_charge = %f WHERE provider_id = %d AND code = %s AND fixed_charge IS NULL',
                    $programs,
                    $charge,
                    $providerId,
                    $code
                )
            );
        }
    }
}
