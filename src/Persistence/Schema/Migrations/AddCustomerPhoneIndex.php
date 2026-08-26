<?php

/**
 * The blind-index column for the phone number, and its backfill.
 *
 * Mirrors `AddCustomerAfmIndex` (0010) exactly, one column later. Encryption
 * is randomised, so once `phone` holds ciphertext the same number produces
 * different bytes on every write and the `LIKE`/`=` lookups in
 * `CustomerRepository::search()` stop matching anything -- silently, the
 * worst kind of failure, because staff searching for a customer by phone
 * would simply see no results and conclude the customer is not in the
 * system.
 *
 * `phone_hash` holds a keyed hash of the same value: stable, so an exact
 * match still works, and useless to anyone without the key. It buys back
 * only full-number search -- like the ΑΦΜ, a partial/substring phone search
 * does not survive encryption and nothing brings it back; see
 * CustomerFields for the whole picture.
 *
 * The backfill runs here, once, over plaintext: `phone` has never been in
 * CustomerFields::ENCRYPTED before this migration ships, so every existing
 * value is still plaintext at this point regardless of whether encryption is
 * switched on elsewhere.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddCustomerPhoneIndex implements Migration
{
    private const INDEX_NAME = 'phone_hash';

    public function id(): string
    {
        return '0020_add_customer_phone_index';
    }

    public function description(): string
    {
        return 'Στήλη phone_hash (blind index) ώστε το τηλέφωνο να παραμείνει αναζητήσιμο κρυπτογραφημένο';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CUSTOMERS);

        if (! $schema->hasTable($table)) {
            return;
        }

        // Fixed identifiers from a closed list; DDL cannot be parameterised.
        $column = CustomerFields::PHONE_INDEX_COLUMN;
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

    /** Hash every phone number that has no index yet. */
    private function backfill(string $table): void
    {
        global $wpdb;

        $fields = CustomerFields::default();
        $column = CustomerFields::PHONE_INDEX_COLUMN;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            "SELECT id, phone FROM `{$table}`
             WHERE phone IS NOT NULL AND phone <> ''
               AND (`{$column}` IS NULL OR `{$column}` = '')",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        foreach ($rows as $row) {
            $wpdb->update(
                $table,
                [CustomerFields::PHONE_INDEX_COLUMN => $fields->index((string) $row['phone'])],
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
