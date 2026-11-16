<?php

/**
 * (405) ΔΕΗ: μόνο τα προγράμματα που δουλεύει ο ιδιοκτήτης.
 *
 * Ο ιδιοκτήτης (16/11) έστειλε τα έντυπα: myHome Enter, EnterTwo, Maxima,
 * 4All, myBusiness Enter, Γ21, myHome GasControl και myBuilding GasControl,
 * «βγάλε τα άλλα».
 *
 * - Γράφει τα νέα (Γ21 και τα δύο του αερίου) με τις τιμές των εντύπων.
 * - Απενεργοποιεί ΚΑΘΕ άλλο πρόγραμμα της ΔΕΗ: τα αποσυρμένα του καταλόγου
 *   και όσα φτιάχτηκαν με το χέρι (π.χ. το γενικό «Σταθερό Οικιακό» χωρίς
 *   κωδικό). Δεν σβήνει τίποτα: οι παλιές αιτήσεις κρατούν το πρόγραμμά τους.
 * - Τα 5 που μένουν από τον κατάλογο ξαναενεργοποιούνται αν κάποιος τα είχε
 *   κλείσει, γιατί ο ιδιοκτήτης τα έστειλε ως τα ενεργά.
 *
 * Τιμή γράφεται μόνο όπου δεν έχει βάλει ο διαχειριστής (price_updated
 * κενό), όπως η 0062.
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

final class RefreshDeiCatalogueNovember2026 implements Migration
{
    /** Τα νέα της 0063. */
    public const NEW = ['dei_g21', 'dei_myhome_gascontrol', 'dei_mybuilding_gascontrol'];

    public function id(): string
    {
        return '0063_refresh_dei_catalogue_november_2026';
    }

    public function description(): string
    {
        return 'ΔΕΗ: μόνο Enter, EnterTwo, Maxima, 4All, myBusiness Enter, Γ21 και GasControl (405)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($providers) || ! $schema->hasTable($programs)) {
            return;
        }

        foreach (['code', 'active', 'price_kwh', 'fixed_charge', 'pricing', 'price_updated'] as $column) {
            if (! $schema->hasColumn($programs, $column)) {
                return;
            }
        }

        $providerId = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE slug = %s', $providers, DeiCatalogue::SLUG)
        );

        if ($providerId <= 0) {
            return;
        }

        $plans  = DeiCatalogue::all();
        $prices = DeiPrices2026::all();

        foreach (self::NEW as $code) {
            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE provider_id = %d AND code = %s',
                    $programs,
                    $providerId,
                    $code
                )
            );

            if ($exists === 0) {
                $wpdb->insert($programs, [
                    'provider_id' => $providerId,
                    'name'        => $plans[$code]['label'],
                    'code'        => $code,
                    'energy_type' => DeiCatalogue::energy($code),
                    'category'    => $plans[$code]['category'],
                    'price_type'  => $plans[$code]['priceType'],
                    'active'      => 1,
                    'sort_order'  => $plans[$code]['sort'],
                ]);
            }

            // update() γράφει NULL για null τιμές και `IS NULL` για null στο WHERE.
            $wpdb->update(
                $programs,
                [
                    'price_kwh'     => $prices[$code]['price_kwh'],
                    'fixed_charge'  => $prices[$code]['fixed_charge'],
                    'pricing'       => (string) json_encode($prices[$code]['pricing'], JSON_UNESCAPED_UNICODE),
                    'price_updated' => DeiCatalogue::AS_OF_NOVEMBER,
                ],
                ['provider_id' => $providerId, 'code' => $code, 'price_updated' => null]
            );
        }

        $offered = array_keys(DeiCatalogue::offered());

        // Ό,τι δεν προσφέρεται κλείνει, ΚΑΙ όσα δεν έχουν κωδικό.
        $placeholders = implode(',', array_fill(0, count($offered), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET active = 0
                 WHERE provider_id = %d AND (code IS NULL OR code = '' OR code NOT IN ({$placeholders}))",
                [$programs, $providerId, ...$offered]
            )
        );
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET active = 1 WHERE provider_id = %d AND code IN ({$placeholders})",
                [$programs, $providerId, ...$offered]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    }
}
