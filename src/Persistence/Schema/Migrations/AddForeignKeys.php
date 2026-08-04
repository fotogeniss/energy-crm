<?php

/**
 * Declares the relationships between tables to the database itself.
 *
 * Until now every parent/child link was maintained by hand: deleting a contract
 * meant remembering five DELETE statements, and forgetting one left rows that
 * nothing could reach. Constraints make the database enforce what the code was
 * only promising.
 *
 * These are defence in depth, not the primary mechanism — documents are still
 * removed by FileRepository, because a CASCADE deletes rows but cannot unlink a
 * file from disk. A relation that fails to apply is therefore logged and
 * skipped rather than treated as fatal.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddForeignKeys implements Migration
{
    /** Orphan rows lose the reference but survive. */
    private const ORPHANS_DETACH = 'detach';

    /** Orphan rows are meaningless on their own and go. */
    private const ORPHANS_DELETE = 'delete';

    /**
     * @return list<array{child: string, column: string, parent: string, onDelete: string, orphans: string}>
     */
    private static function relations(): array
    {
        return [
            // A tariff programme without its provider means nothing.
            ['child' => Tables::PROGRAMS, 'column' => 'provider_id', 'parent' => Tables::PROVIDERS,
                'onDelete' => 'CASCADE', 'orphans' => self::ORPHANS_DELETE],

            // A contract outlives the records it points at: erasing a customer
            // for GDPR must not destroy the commercial history.
            ['child' => Tables::CONTRACTS, 'column' => 'customer_id', 'parent' => Tables::CUSTOMERS,
                'onDelete' => 'SET NULL', 'orphans' => self::ORPHANS_DETACH],
            ['child' => Tables::CONTRACTS, 'column' => 'provider_id', 'parent' => Tables::PROVIDERS,
                'onDelete' => 'SET NULL', 'orphans' => self::ORPHANS_DETACH],
            ['child' => Tables::CONTRACTS, 'column' => 'program_id', 'parent' => Tables::PROGRAMS,
                'onDelete' => 'SET NULL', 'orphans' => self::ORPHANS_DETACH],
            ['child' => Tables::CONTRACTS, 'column' => 'payout_id', 'parent' => Tables::PAYOUTS,
                'onDelete' => 'SET NULL', 'orphans' => self::ORPHANS_DETACH],

            // These exist only as part of a contract.
            ['child' => Tables::FILES, 'column' => 'contract_id', 'parent' => Tables::CONTRACTS,
                'onDelete' => 'CASCADE', 'orphans' => self::ORPHANS_DELETE],
            ['child' => Tables::EVENTS, 'column' => 'contract_id', 'parent' => Tables::CONTRACTS,
                'onDelete' => 'CASCADE', 'orphans' => self::ORPHANS_DELETE],
            ['child' => Tables::SIGNATURES, 'column' => 'contract_id', 'parent' => Tables::CONTRACTS,
                'onDelete' => 'CASCADE', 'orphans' => self::ORPHANS_DELETE],
            ['child' => Tables::NOTIFICATIONS, 'column' => 'contract_id', 'parent' => Tables::CONTRACTS,
                'onDelete' => 'CASCADE', 'orphans' => self::ORPHANS_DELETE],

            // A task or lead belongs to a person, not to a contract; losing the
            // contract must not silently remove someone's to-do.
            ['child' => Tables::TASKS, 'column' => 'contract_id', 'parent' => Tables::CONTRACTS,
                'onDelete' => 'SET NULL', 'orphans' => self::ORPHANS_DETACH],
            ['child' => Tables::TASKS, 'column' => 'customer_id', 'parent' => Tables::CUSTOMERS,
                'onDelete' => 'SET NULL', 'orphans' => self::ORPHANS_DETACH],
            ['child' => Tables::LEADS, 'column' => 'contract_id', 'parent' => Tables::CONTRACTS,
                'onDelete' => 'SET NULL', 'orphans' => self::ORPHANS_DETACH],

            ['child' => Tables::COMMISSION_RULES, 'column' => 'provider_id', 'parent' => Tables::PROVIDERS,
                'onDelete' => 'CASCADE', 'orphans' => self::ORPHANS_DELETE],
            ['child' => Tables::COMMISSION_RULES, 'column' => 'program_id', 'parent' => Tables::PROGRAMS,
                'onDelete' => 'CASCADE', 'orphans' => self::ORPHANS_DELETE],

            // Knowledge-base entries keep a provider_name of their own.
            ['child' => Tables::KB_ENTRIES, 'column' => 'provider_id', 'parent' => Tables::PROVIDERS,
                'onDelete' => 'SET NULL', 'orphans' => self::ORPHANS_DETACH],
        ];
    }

    public function id(): string
    {
        return '0004_add_foreign_keys';
    }

    public function description(): string
    {
        return 'Foreign keys με ON DELETE, ώστε να μη μένουν ορφανές εγγραφές';
    }

    public function apply(SchemaInspector $schema): void
    {
        foreach (self::relations() as $relation) {
            $this->applyRelation($schema, $relation);
        }
    }

    /**
     * @param array{child: string, column: string, parent: string, onDelete: string, orphans: string} $relation
     */
    private function applyRelation(SchemaInspector $schema, array $relation): void
    {
        global $wpdb;

        $child  = Tables::name($relation['child']);
        $parent = Tables::name($relation['parent']);
        $column = $relation['column'];
        $name   = $this->constraintName($relation);

        if (! $schema->hasTable($child) || ! $schema->hasTable($parent)) {
            return;
        }

        if (! $schema->hasColumn($child, $column) || $schema->hasConstraint($child, $name)) {
            return;
        }

        $this->clearOrphans($child, $column, $parent, $relation['orphans']);

        // Every identifier here comes from the closed set in relations();
        // DDL cannot be parameterised.
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $applied = $wpdb->query(
            "ALTER TABLE `{$child}`
             ADD CONSTRAINT `{$name}`
             FOREIGN KEY (`{$column}`) REFERENCES `{$parent}` (`id`)
             ON DELETE {$relation['onDelete']}"
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        if ($applied === false) {
            error_log(sprintf(
                '[Energy CRM] Το foreign key %s δεν εφαρμόστηκε: %s',
                $name,
                $wpdb->last_error
            ));
        }
    }

    /**
     * MySQL refuses to create a constraint that existing rows already violate,
     * so the debris has to go first.
     */
    private function clearOrphans(string $child, string $column, string $parent, string $strategy): void
    {
        global $wpdb;

        $sql = $strategy === self::ORPHANS_DELETE
            ? "DELETE c FROM `{$child}` c
               LEFT JOIN `{$parent}` p ON p.id = c.`{$column}`
               WHERE c.`{$column}` IS NOT NULL AND p.id IS NULL"
            : "UPDATE `{$child}` c
               LEFT JOIN `{$parent}` p ON p.id = c.`{$column}`
               SET c.`{$column}` = NULL
               WHERE c.`{$column}` IS NOT NULL AND p.id IS NULL";

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $wpdb->query($sql);
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    }

    /**
     * @param array{child: string, column: string, parent: string, onDelete: string, orphans: string} $relation
     */
    private function constraintName(array $relation): string
    {
        global $wpdb;

        // Constraint names are database-wide, so the site prefix has to be in
        // there for installations sharing one database.
        return sprintf('%secrm_fk_%s_%s', $wpdb->prefix, $relation['child'], $relation['column']);
    }
}
