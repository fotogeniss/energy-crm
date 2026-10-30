<?php

/**
 * (328) Στήλες programs.pricing και programs.price_updated.
 *
 * `pricing`: ο κανόνας τιμής όταν μία τιμή €/kWh δεν αρκεί -- κλιμάκια
 * (Value Balance) ή πακέτο (Picasso) -- σε JSON. Βλ. Domain\Pricing\ProgramPricing.
 *
 * `price_updated`: πότε γράφτηκε η τιμή. Τα κυμαινόμενα αλλάζουν κάθε μήνα,
 * και μια σύγκριση με τιμή τριών μηνών πρέπει να φαίνεται ότι είναι παλιά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddProgramPricingColumns implements Migration
{
    public function id(): string
    {
        return '0047_add_program_pricing_columns';
    }

    public function description(): string
    {
        return 'Στήλες programs.pricing (κλιμάκια/πακέτο) και programs.price_updated (328)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($table)) {
            return;
        }

        // Identifiers are constants; DDL cannot be parameterised.
        // phpcs:disable WordPress.DB.PreparedSQL
        if (! $schema->hasColumn($table, 'pricing')) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `pricing` TEXT NULL");
        }

        if (! $schema->hasColumn($table, 'price_updated')) {
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `price_updated` DATE NULL");
        }
        // phpcs:enable WordPress.DB.PreparedSQL
    }
}
