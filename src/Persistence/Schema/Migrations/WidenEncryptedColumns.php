<?php

/**
 * Makes room for ciphertext in the columns that hold it.
 *
 * Encryption turns nine digits into about seventy-four characters: a marker,
 * a 24-byte nonce, the value, a 16-byte authentication tag, base64-encoded.
 * `afm` was VARCHAR(20). The value could not fit, and `postal_code` at
 * VARCHAR(12) was worse.
 *
 * What happens then depends on the server, and both answers are bad. In strict
 * mode the write fails and the agent cannot save the application. Without
 * strict mode — still common on shared hosting — MySQL **truncates and carries
 * on**: the row saves, the column holds the first twenty characters of
 * ciphertext, and nothing can ever decrypt it again. Silent, permanent loss of
 * the customer's tax number, discovered whenever somebody next opens the file.
 *
 * The integration suite caught this on its first run, before the switch was
 * ever turned on anywhere real.
 *
 * Widths are generous on purpose: a VARCHAR costs only what it stores, and
 * being wrong here costs data. `street` needs the most room because it holds
 * the longest plaintext.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class WidenEncryptedColumns implements Migration
{
    /**
     * Column => the definition it needs to hold ciphertext.
     *
     * These are the columns CustomerFields encrypts. Add one there, add it
     * here, or the first encrypted write silently loses it.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'afm'         => 'VARCHAR(255) NULL',
        'adt'         => 'VARCHAR(255) NULL',
        'street'      => 'VARCHAR(512) NULL',
        'street_no'   => 'VARCHAR(255) NULL',
        'postal_code' => 'VARCHAR(255) NULL',
    ];

    public function id(): string
    {
        return '0011_widen_encrypted_columns';
    }

    public function description(): string
    {
        return 'Πλάτος στηλών ώστε να χωράει κρυπτογραφημένη τιμή χωρίς σιωπηλή περικοπή';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CUSTOMERS);

        if (! $schema->hasTable($table)) {
            return;
        }

        foreach (self::COLUMNS as $column => $definition) {
            if (! $schema->hasColumn($table, $column)) {
                continue;
            }

            // Widening only: MODIFY never shortens here, so the statement is
            // safe to re-run and cannot itself truncate anything.
            // Identifiers come from the closed list above; DDL cannot be
            // parameterised.
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}");
        }
    }
}
