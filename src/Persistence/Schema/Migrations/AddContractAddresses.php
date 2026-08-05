<?php

/**
 * Gives a contract its own supply and billing addresses.
 *
 * Until now the CRM held one address per customer and printed it in all three
 * boxes every provider form has. That is right most of the time and wrong
 * exactly when it counts — a meter in a rented shop, bills going to the
 * accountant.
 *
 * They belong on the contract rather than the customer because they describe a
 * supply, not a person: the same customer can hold a home supply and a shop
 * supply, each with its own meter address.
 *
 * Both flags default to 1, so every existing contract keeps saying what it has
 * always said and nothing on screen changes until someone unticks a box.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddContractAddresses implements Migration
{
    /**
     * @return array<string, string>
     */
    private static function columns(): array
    {
        $address = [
            'street'      => 'VARCHAR(180) NULL',
            'street_no'   => 'VARCHAR(20)  NULL',
            'city'        => 'VARCHAR(120) NULL',
            'postal_code' => 'VARCHAR(12)  NULL',
            'region'      => 'VARCHAR(120) NULL',
        ];

        $columns = [];

        foreach (['supply_', 'billing_'] as $prefix) {
            $columns[$prefix . 'addr_same'] = 'TINYINT(1) NOT NULL DEFAULT 1';

            foreach ($address as $column => $definition) {
                $columns[$prefix . $column] = $definition;
            }
        }

        return $columns;
    }

    public function id(): string
    {
        return '0005_add_contract_addresses';
    }

    public function description(): string
    {
        return 'Διεύθυνση παροχής και διεύθυνση αποστολής λογαριασμού ανά σύμβαση';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CONTRACTS);

        if (! $schema->hasTable($table)) {
            return;
        }

        foreach (self::columns() as $column => $definition) {
            if ($schema->hasColumn($table, $column)) {
                continue;
            }

            // Identifiers come from the closed list above; no request data
            // reaches this statement, and DDL cannot be parameterised.
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }
}
