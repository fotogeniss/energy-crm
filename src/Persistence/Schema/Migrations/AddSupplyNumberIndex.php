<?php

/**
 * Index on contracts.supply_number — the column the Excel-status import
 * filters on, once per row, with no index at all.
 *
 * `ECRM_Import::apply()` (see the matching-bug fix in CHANGELOG (161)) runs
 *
 *     SELECT ... WHERE supply_number = %s AND partner_user_id IN (...)
 *     ORDER BY id DESC LIMIT 1
 *
 * once per row of the uploaded file. Measured 27/08/2026: no index exists on
 * this column, so every one of those lookups is a full table scan of
 * `contracts` — up to 2000 rows in a single import, run interactively while
 * the owner's tab is open and waiting. The same column is read with an exact
 * `=` match in `ContractQueries` (the duplicate-check the wizard runs while
 * typing); the `LIKE '%...%'` search there stays a scan regardless of any
 * index, which is why this migration adds one index, not a rewrite of that
 * search.
 *
 * `supply_number` is plaintext (not in the encrypted-column lists), so this
 * is an ordinary index — no blind-index/backfill dance like the ΑΦΜ or phone
 * columns needed once those became ciphertext.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddSupplyNumberIndex implements Migration
{
    private const INDEX_NAME = 'supply_number';

    public function id(): string
    {
        return '0023_add_supply_number_index';
    }

    public function description(): string
    {
        return 'Index στο contracts.supply_number — η εισαγωγή κατάστασης παρόχου έκανε πλήρη σάρωση ανά γραμμή';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CONTRACTS);

        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, self::INDEX_NAME)) {
            return;
        }

        if ($this->hasIndex($table, self::INDEX_NAME)) {
            return;
        }

        // Fixed identifiers from a closed list; DDL cannot be parameterised.
        $index = self::INDEX_NAME;

        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`{$index}`)");
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
