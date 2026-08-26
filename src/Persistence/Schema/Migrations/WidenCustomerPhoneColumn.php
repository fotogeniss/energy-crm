<?php

/**
 * Makes room for ciphertext in `customers.phone`.
 *
 * Same problem `WidenEncryptedColumns` (0011) solved for `afm`/`adt`/`street`/
 * `street_no`/`postal_code`, one column later. `phone` was `VARCHAR(40)` --
 * encryption turns ten digits into about seventy-four characters, so the
 * value cannot fit. Without strict SQL mode the write does not fail, it
 * truncates and carries on: the row saves, the column holds the first forty
 * characters of ciphertext, and the phone number is gone for good.
 *
 * A separate migration rather than adding a row to `WidenEncryptedColumns`'s
 * own `COLUMNS` map: that migration already shipped and is recorded as done
 * by id on every site that ran it, so a column added to its map now would
 * simply never run there. See MigrationList's own "append only" rule.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class WidenCustomerPhoneColumn implements Migration
{
    private const DEFINITION = 'VARCHAR(255) NULL';

    public function id(): string
    {
        return '0019_widen_customer_phone_column';
    }

    public function description(): string
    {
        return 'Πλάτος στήλης phone ώστε να χωράει κρυπτογραφημένη τιμή χωρίς σιωπηλή περικοπή';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CUSTOMERS);

        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'phone')) {
            return;
        }

        // Widening only: MODIFY never shortens here, so the statement is safe
        // to re-run and cannot itself truncate anything. The column name is
        // a fixed literal, not user input; DDL cannot be parameterised.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("ALTER TABLE `{$table}` MODIFY COLUMN `phone` " . self::DEFINITION);
    }
}
