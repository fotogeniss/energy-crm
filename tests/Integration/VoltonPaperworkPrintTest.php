<?php

/**
 * (456) Volton: η αίτηση τυπώνει και τους Ειδικούς Όρους του προγράμματος,
 * και η μετάβαση 0067 φέρνει τα νέα κίτρινα προγράμματα στη βάση.
 *
 * @kanonas K7
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_FormFill;
use EnergyCRM\Persistence\Schema\Migrations\AddVoltonYellowNovember2026;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class VoltonPaperworkPrintTest extends IntegrationTestCase
{
    public function testTheApplicationCarriesTheProgramTermsAndThePromotion(): void
    {
        $keys = ECRM_FormFill::template_keys([
            'provider_name' => 'Volton',
            'energy_type'   => 'power',
            'program_code'  => 'volton_stay_win_g22',
        ]);

        self::assertSame(['volton_he', 'volton_eo_stay_win_g22', 'volton_promo_stay_win_g22'], $keys);
    }

    /** Υπογεγραμμένη πριν: το ίδιο χαρτί που υπέγραψε ο πελάτης (K1). */
    public function testAnOlderSignedContractPrintsWhatWasSigned(): void
    {
        $keys = ECRM_FormFill::template_keys([
            'provider_name' => 'Volton',
            'energy_type'   => 'power',
            'program_code'  => 'volton_stay_win',
            'signed_at'     => '2026-11-10 12:00:00',
        ]);

        self::assertSame(['volton_he'], $keys);
    }

    public function testTheMigrationAddsTheNewPlansAndFillsOnlyEmptyCharges(): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        $provider = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, 'volton'));

        if ($provider <= 0) {
            $wpdb->insert($providers, ['slug' => 'volton', 'name' => 'Volton']);
            $provider = (int) $wpdb->insert_id;
        }

        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE provider_id = %d AND code IN '
            . "('volton_yellow_protect_oct26', 'volton_yellow_standard_g21',"
            . " 'volton_yellow_simple_g22', 'volton_yellow_simple_g23')",
            $programs,
            $provider
        ));
        $wpdb->insert($programs, [
            'provider_id' => $provider, 'name' => 'Simple Γ22', 'code' => 'volton_yellow_simple_g22',
            'energy_type' => 'power', 'category' => 'business', 'price_type' => 'variable', 'active' => 1,
        ]);
        // Ο διαχειριστής έβαλε ήδη πάγιο: δεν το πατάμε.
        $wpdb->insert($programs, [
            'provider_id' => $provider, 'name' => 'Simple Γ23', 'code' => 'volton_yellow_simple_g23',
            'energy_type' => 'power', 'category' => 'business', 'price_type' => 'variable', 'active' => 1,
            'fixed_charge' => 7.50,
        ]);

        (new AddVoltonYellowNovember2026())->apply(new SchemaInspector());
        (new AddVoltonYellowNovember2026())->apply(new SchemaInspector());

        $charge = static fn (string $code): ?string => $wpdb->get_var($wpdb->prepare(
            'SELECT fixed_charge FROM %i WHERE provider_id = %d AND code = %s',
            $programs,
            $provider,
            $code
        ));
        $count  = static fn (string $code): int => (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE provider_id = %d AND code = %s',
            $programs,
            $provider,
            $code
        ));

        self::assertSame(1, $count('volton_yellow_protect_oct26'));
        self::assertSame(1, $count('volton_yellow_standard_g21'));
        self::assertEqualsWithDelta(10.90, (float) $charge('volton_yellow_protect_oct26'), 0.001);
        self::assertEqualsWithDelta(6.90, (float) $charge('volton_yellow_simple_g22'), 0.001);
        self::assertEqualsWithDelta(7.50, (float) $charge('volton_yellow_simple_g23'), 0.001);
    }
}
