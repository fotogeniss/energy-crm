<?php

/**
 * The blind-index column for the ΑΦΜ, and its backfill.
 *
 * Encryption is randomised, so once `afm` holds ciphertext the same tax number
 * produces different bytes on every write and `WHERE afm = %s` stops matching
 * anything. Duplicate detection and lookup by ΑΦΜ would silently return
 * nothing — the worst kind of failure, because an agent would read it as "no
 * duplicate exists" and file a second application.
 *
 * `afm_hash` holds a keyed hash of the same value: stable, so equality works,
 * and useless to anyone without the key. See CustomerFields for the whole
 * picture.
 *
 * The backfill runs here rather than lazily because a half-filled index is
 * indistinguishable from "no such customer". Every existing row is plaintext
 * at this point, so it is a straight read-hash-write over a table measured in
 * thousands — once, in a migration, not on a request.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddCustomerAfmIndex implements Migration
{
    private const INDEX_NAME = 'afm_hash';

    public function id(): string
    {
        return '0010_add_customer_afm_index';
    }

    public function description(): string
    {
        return 'Στήλη afm_hash (blind index) ώστε το ΑΦΜ να παραμείνει αναζητήσιμο κρυπτογραφημένο';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CUSTOMERS);

        if (! $schema->hasTable($table)) {
            return;
        }

        // Fixed identifiers from a closed list; DDL cannot be parameterised.
        $column = CustomerFields::INDEX_COLUMN;
        $index  = self::INDEX_NAME;

        if (! $schema->hasColumn($table, $column)) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` CHAR(64) NULL");
        }

        if (! $this->hasIndex($table, $index)) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`{$column}`)");
        }

        $this->backfill($table);
    }

    /** Hash every ΑΦΜ that has no index yet. */
    private function backfill(string $table): void
    {
        global $wpdb;

        $fields = CustomerFields::default();
        $column = CustomerFields::INDEX_COLUMN;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            "SELECT id, afm FROM `{$table}`
             WHERE afm IS NOT NULL AND afm <> ''
               AND (`{$column}` IS NULL OR `{$column}` = '')",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        foreach ($rows as $row) {
            $wpdb->update(
                $table,
                [CustomerFields::INDEX_COLUMN => $fields->index((string) $row['afm'])],
                ['id' => (int) $row['id']]
            );
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
