<?php

/**
 * Questions about the current shape of the database.
 *
 * Migrations must be safe to run against a schema they did not create: a fresh
 * install gets its tables from dbDelta with every column already present, while
 * an upgraded site may be missing any subset of them. Asking before altering is
 * what makes the same migration correct in both cases.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema;

final class SchemaInspector
{
    public function hasTable(string $table): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            )
        ) > 0;
    }

    public function hasColumn(string $table, string $column): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                $column
            )
        ) > 0;
    }

    /**
     * Υπάρχει ήδη αυτό το ευρετήριο;
     *
     * Ρωτιέται με το ΟΝΟΜΑ και όχι με τις στήλες: δύο ευρετήρια πάνω στις ίδιες
     * στήλες με άλλη σειρά είναι διαφορετικά πράγματα, και ένας έλεγχος «υπάρχει
     * κάτι πάνω σε αυτή τη στήλη» θα προσπερνούσε σιωπηλά τη μετάπτωση αφήνοντας
     * το σωστό ευρετήριο να μη φτιαχτεί ποτέ.
     */
    public function hasIndex(string $table, string $index): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
                $table,
                $index
            )
        ) > 0;
    }

    public function hasConstraint(string $table, string $constraint): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s',
                $table,
                $constraint
            )
        ) > 0;
    }

    public function engine(string $table): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            )
        );
    }
}
