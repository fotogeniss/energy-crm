<?php

/**
 * Moves any MyISAM table to InnoDB.
 *
 * MyISAM accepts foreign key syntax and then ignores it — no error, no
 * constraint, just the quiet impression that referential integrity exists.
 * This runs before the constraints are added so that failure is loud instead.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class EnsureInnoDb implements Migration
{
    public function id(): string
    {
        return '0003_ensure_innodb';
    }

    public function description(): string
    {
        return 'Μετατροπή τυχόν MyISAM πινάκων σε InnoDB';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        foreach (Tables::all() as $table) {
            $name = Tables::name($table);

            if (! $schema->hasTable($name)) {
                continue;
            }

            if (strtoupper($schema->engine($name)) === 'INNODB') {
                continue;
            }

            // Table name comes from Tables::all(); DDL cannot be parameterised.
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$name}` ENGINE = InnoDB");
        }
    }
}
