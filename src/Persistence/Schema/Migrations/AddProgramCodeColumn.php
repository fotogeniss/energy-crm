<?php

/**
 * Gives each program a stable `code`, independent of its display name.
 *
 * ECRM_FormFill needs to know which of the four Orizon plans a contract is
 * for, so it can print that plan's fixed price. Matching on the program's
 * `name` would tie a printed price to a string an operator can freely edit in
 * wp-admin — rename "orizon 5GB" by one character and the match silently
 * stops firing, and the form prints with no plan ticked and no price at all.
 *
 * `code` is not shown anywhere in the admin UI. It is only ever written from
 * `MobilePlans::codes()`, so it can drift only if that constant list changes —
 * and then both sides (the seed data and the lookup) change together, in the
 * same file.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddProgramCodeColumn implements Migration
{
    public function id(): string
    {
        return '0012_add_program_code_column';
    }

    public function description(): string
    {
        return 'Στήλη programs.code — σταθερό αναγνωριστικό πλάνου, ανεξάρτητο από το όνομα';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($table)) {
            return;
        }

        if (! $schema->hasColumn($table, 'code')) {
            // Fixed identifier, closed list; DDL cannot be parameterised.
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `code` VARCHAR(32) NULL AFTER `name`");
        }

        if (! $this->hasIndex($table, 'code')) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `code` (`code`)");
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $found = $wpdb->get_var(
            $wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $name)
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $found !== null;
    }
}
