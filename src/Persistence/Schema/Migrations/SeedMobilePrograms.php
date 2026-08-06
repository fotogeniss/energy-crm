<?php

/**
 * Gives mobile providers a program to choose, on sites that already exist.
 *
 * The seeder creates a starter program only when a provider was inserted, and
 * only for electricity. Orizon was therefore listed with no program at all, so
 * the dropdown read "—" and the agent had nothing to pick.
 *
 * It matters more than an empty list: the combined-offer form is selected by
 * the program's name, so without a program named for it that template could
 * never be produced.
 *
 * Only adds what is missing, and only for providers that sell mobile — a
 * provider that already has mobile programs is left alone.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class SeedMobilePrograms implements Migration
{
    /** @var list<string> */
    private const STARTERS = [
        'Κινητή — Βασικό',
        'Κινητή — Συνδυαστική Προσφορά (Family)',
    ];

    public function id(): string
    {
        return '0009_seed_mobile_programs';
    }

    public function description(): string
    {
        return 'Αρχικά προγράμματα κινητής για παρόχους που την προσφέρουν';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $providers = Tables::name(Tables::PROVIDERS);
        $programs  = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($providers) || ! $schema->hasTable($programs)) {
            return;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.id FROM %i p
                 WHERE p.energy_types LIKE %s
                   AND NOT EXISTS (
                       SELECT 1 FROM %i g
                       WHERE g.provider_id = p.id AND g.energy_type = 'mobile'
                   )",
                [$providers, '%mobile%', $programs]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        foreach ((array) $ids as $providerId) {
            foreach (self::STARTERS as $order => $name) {
                $wpdb->insert($programs, [
                    'provider_id' => (int) $providerId,
                    'name'        => $name,
                    'energy_type' => 'mobile',
                    'category'    => 'home',
                    'price_type'  => 'fixed',
                    'active'      => 1,
                    'sort_order'  => $order,
                ]);
            }
        }
    }
}
