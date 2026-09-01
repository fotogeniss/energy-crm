<?php

/**
 * Foreign keys για τον νέο πίνακα `guarantee_rules`.
 *
 * Το `AddForeignKeys` (0004) δίνει ήδη ακριβώς αυτές τις δύο σχέσεις στους
 * κανόνες προμήθειας, με τα ίδια ονόματα και την ίδια συμπεριφορά. Δεν
 * προστέθηκαν εκεί επειδή εκείνη η μετανάστευση έχει ήδη τρέξει σε κάθε
 * εγκατάσταση και ο runner δεν την ξανατρέχει: μια νέα γραμμή στη λίστα της θα
 * ίσχυε μόνο για εγκαταστάσεις που δεν υπάρχουν ακόμη.
 *
 * Γιατί έχει σημασία και δεν είναι τυπικότητα: ένας κανόνας που δείχνει σε
 * διαγραμμένο πάροχο δεν σκάει — απλώς σταματά να ταιριάζει, ή χειρότερα,
 * ταιριάζει σε άλλον πάροχο αν το id ξαναδοθεί. Το αποτέλεσμα θα ήταν λάθος
 * ποσό εγγύησης, προτεινόμενο με την ίδια σιγουριά με το σωστό.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddGuaranteeRuleForeignKeys implements Migration
{
    public function id(): string
    {
        return '0027_add_guarantee_rule_foreign_keys';
    }

    public function description(): string
    {
        return 'Foreign keys guarantee_rules → providers/programs, ώστε να μη μένουν ορφανοί κανόνες';
    }

    public function apply(SchemaInspector $schema): void
    {
        foreach (['provider_id' => Tables::PROVIDERS, 'program_id' => Tables::PROGRAMS] as $column => $parent) {
            $this->applyRelation($schema, (string) $column, $parent);
        }
    }

    private function applyRelation(SchemaInspector $schema, string $column, string $parentTable): void
    {
        global $wpdb;

        $child  = Tables::name(Tables::GUARANTEE_RULES);
        $parent = Tables::name($parentTable);

        // Ονόματα περιορισμών είναι μοναδικά σε ΟΛΗ τη βάση, οπότε το prefix
        // πρέπει να είναι μέσα — ίδιος λόγος και ίδιο σχήμα με το 0004.
        $name = sprintf('%secrm_fk_%s_%s', $wpdb->prefix, Tables::GUARANTEE_RULES, $column);

        if (! $schema->hasTable($child) || ! $schema->hasTable($parent)) {
            return;
        }

        if (! $schema->hasColumn($child, $column) || $schema->hasConstraint($child, $name)) {
            return;
        }

        // Η MySQL αρνείται περιορισμό που ήδη παραβιάζεται, οπότε τα σκουπίδια
        // φεύγουν πρώτα. Κανόνας χωρίς υπαρκτό πάροχο δεν έχει νόημα ούτως ή
        // άλλως — ίδια επιλογή με τους κανόνες προμήθειας (ORPHANS_DELETE).
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $wpdb->query(
            "DELETE c FROM `{$child}` c
             LEFT JOIN `{$parent}` p ON p.id = c.`{$column}`
             WHERE c.`{$column}` IS NOT NULL AND p.id IS NULL"
        );

        $applied = $wpdb->query(
            "ALTER TABLE `{$child}`
             ADD CONSTRAINT `{$name}`
             FOREIGN KEY (`{$column}`) REFERENCES `{$parent}` (`id`)
             ON DELETE CASCADE"
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        if (false === $applied) {
            error_log(sprintf(
                '[Energy CRM] Το foreign key %s δεν εφαρμόστηκε: %s',
                $name,
                $wpdb->last_error
            ));
        }
    }
}
