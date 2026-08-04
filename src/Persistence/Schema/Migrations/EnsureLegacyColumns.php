<?php

/**
 * Adds the columns that dbDelta silently skipped.
 *
 * dbDelta parses CREATE TABLE statements with a regex and gives up on lines it
 * cannot read — inline comments after a column definition being the classic
 * trigger. The old code compensated with a hand-written ensure_columns() that
 * ran SHOW COLUMNS on every request. This migration is that logic, executed
 * once and recorded, instead of re-derived forever.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class EnsureLegacyColumns implements Migration
{
    /**
     * Column definitions per table, keyed by the Tables constant.
     *
     * @return array<string, array<string, string>>
     */
    private static function columns(): array
    {
        return [
            'contracts' => [
                'extra_json'  => 'LONGTEXT NULL',
                'start_date'  => 'DATE NULL',
                'term_months' => 'INT NULL',
                'end_date'    => 'DATE NULL',
                'payout_id'   => 'BIGINT UNSIGNED NULL',
                'consent_at'  => 'DATETIME NULL',
                'consent_ip'  => 'VARCHAR(64) NULL',
                'signed_at'   => 'DATETIME NULL',
                'signed_ip'   => 'VARCHAR(64) NULL',
            ],
            'providers' => [
                'logo_url' => 'VARCHAR(300) NULL',
            ],
            'programs' => [
                'price_kwh'    => 'DECIMAL(8,5) NULL',
                'fixed_charge' => 'DECIMAL(8,2) NULL',
            ],
            'files' => [
                'protected' => 'TINYINT NOT NULL DEFAULT 0',
            ],
        ];
    }

    public function id(): string
    {
        return '0001_ensure_legacy_columns';
    }

    public function description(): string
    {
        return 'Στήλες που παρέλειψε το dbDelta (πρώην ensure_columns)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        foreach (self::columns() as $table => $columns) {
            $name = Tables::name($table);

            if (! $schema->hasTable($name)) {
                continue;
            }

            foreach ($columns as $column => $definition) {
                if ($schema->hasColumn($name, $column)) {
                    continue;
                }

                // Identifiers come from the closed set above; no request data
                // reaches this statement, and DDL cannot be parameterised.
                // phpcs:ignore WordPress.DB.PreparedSQL
                $wpdb->query("ALTER TABLE `{$name}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }
}
