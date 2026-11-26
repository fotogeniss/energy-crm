<?php

/**
 * (457) Τα νέα μπλε (σταθερά) προγράμματα της Volton, από 23/11/2026.
 *
 * Πέντε προγράμματα που η Volton πουλά από τη Δευτέρα 23/11 και το CRM δεν
 * είχε: Blue Fixed Plus 18M και Blue Flat 12M (οικιακά), Blue Fixed Plus 18M,
 * Blue Flat 24M και Blue Flat 12M Business Γ21. Τα παλιά μπλε μένουν όπως είναι
 * (απόφαση ιδιοκτήτη 26/11): δεν απενεργοποιούνται από εδώ.
 *
 * Ίδιος τρόπος με το 0067: ό,τι λείπει μπαίνει, ό,τι υπάρχει δεν αγγίζεται.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Forms\VoltonPlans;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddVoltonBlueNovember2026 implements Migration
{
    public const NEW = [
        'volton_blue_fixed_plus_18m',
        'volton_blue_flat_12m',
        'volton_blue_fixed_plus_18m_g21',
        'volton_blue_flat_24m_g21',
        'volton_blue_flat_12m_g21',
    ];

    public function id(): string
    {
        return '0068_add_volton_blue_november_2026';
    }

    public function description(): string
    {
        return 'Volton: νέα μπλε Fixed Plus 18M, Flat 12M (οικιακά) και Γ21 Fixed Plus 18M, Flat 24M, Flat 12M';
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
    }
}
