<?php

/**
 * Composite indexes for the two queries the CRM runs most.
 *
 * The contracts table already indexes partner_user_id and status, but
 * separately. MySQL picks one of them and filters the rest by hand, then sorts
 * the survivors — and the list screen is exactly "these partners, this status,
 * newest first". With a hundred contracts nobody notices; with a hundred
 * thousand it is a filesort on every page load, on every agent's screen.
 *
 * The second index is for the dashboard and the analytics trend, which group by
 * month and had no index on created_at at all.
 *
 * Adding an index locks the table briefly. These are small statements on a
 * table measured in thousands of rows, but they run once, in a migration,
 * rather than on a request.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddContractListIndexes implements Migration
{
    /** Index name => the columns it covers, in order. */
    private const INDEXES = [
        'partner_status_created' => '(partner_user_id, status, created_at)',
        'created_at'             => '(created_at)',
    ];

    public function id(): string
    {
        return '0007_add_contract_list_indexes';
    }

    public function description(): string
    {
        return 'Σύνθετα index για τη λίστα συμβάσεων και τα στατιστικά';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CONTRACTS);

        if (! $schema->hasTable($table)) {
            return;
        }

        foreach (self::INDEXES as $name => $columns) {
            if ($this->hasIndex($table, $name)) {
                continue;
            }

            // Names and columns come from the closed list above; no request
            // data reaches this statement and DDL cannot be parameterised.
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}");
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
