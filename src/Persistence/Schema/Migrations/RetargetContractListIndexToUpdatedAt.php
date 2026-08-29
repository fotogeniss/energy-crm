<?php

/**
 * Ξαναστοχεύει το composite index της λίστας συμβάσεων σε updated_at.
 *
 * ## Το λάθος που διορθώνει
 *
 * `AddContractListIndexes` (0007) έχτισε `(partner_user_id, status,
 * created_at)` με το σκεπτικό «αυτοί οι συνεργάτες, αυτή η κατάσταση, πιο
 * πρόσφατα πρώτα» -- αλλά η ίδια η λίστα (`ecrm-view-contracts.js:224`)
 * δείχνει και ταξινομεί σε `updated_at` («πριν Χ ώρες»), όχι `created_at`.
 * Η υπόθεση ήταν λάθος στο ΙΔΙΟ σημείο: ποια στήλη σημαίνει «πρόσφατο» για
 * αυτή την οθόνη. Ο κώδικας (`ContractQueries::search()` κ.ά., `ORDER BY
 * updated_at`) ήταν συνεπής με αυτό που βλέπει ο πωλητής· το index
 * στόχευε σε άλλη στήλη.
 *
 * Μετρήθηκε 29/08 με 2.500 συνθετικές γραμμές (tools/measure-contract-list.php):
 * χωρίς φίλτρο κατάστασης, `ORDER BY updated_at` σάρωνε ΚΑΙ ΤΙΣ 2506
 * γραμμές (key=NULL, filesort) για να κρατήσει 200· `ORDER BY created_at`
 * (που καλύπτει το παλιό index) άγγιζε ακριβώς 200. Το ίδιο κόστος
 * μεταφέρεται εδώ, στη σωστή στήλη -- οπότε λύνεται, όχι απλώς μετακομίζει.
 *
 * Δεν αγγίζει το μεμονωμένο index `created_at` του ίδιου 0007 migration --
 * εκείνο εξυπηρετεί το dashboard/analytics trend που ΟΝΤΩΣ ομαδοποιεί σε
 * μήνα δημιουργίας, άσχετο ζήτημα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class RetargetContractListIndexToUpdatedAt implements Migration
{
    private const OLD_INDEX = 'partner_status_created';

    private const NEW_INDEX = 'partner_status_updated';

    public function id(): string
    {
        return '0025_retarget_contract_list_index_to_updated_at';
    }

    public function description(): string
    {
        return 'Το composite index της λίστας συμβάσεων ακολουθεί το updated_at που όντως ταξινομεί η οθόνη';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CONTRACTS);

        if (! $schema->hasTable($table)) {
            return;
        }

        // Το όνομα, όχι οι στήλες, είναι το κλειδί -- SchemaInspector::hasIndex().
        // Τα ονόματα/στήλες προέρχονται από τις σταθερές παραπάνω· καμία
        // είσοδος αιτήματος δεν φτάνει εδώ, και DDL δεν παραμετροποιείται.
        // phpcs:disable WordPress.DB.PreparedSQL
        if ($schema->hasIndex($table, self::OLD_INDEX)) {
            $wpdb->query("ALTER TABLE `{$table}` DROP INDEX `" . self::OLD_INDEX . '`');
        }

        if (! $schema->hasIndex($table, self::NEW_INDEX)) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD INDEX `" . self::NEW_INDEX . '` (partner_user_id, status, updated_at)'
            );
        }
        // phpcs:enable WordPress.DB.PreparedSQL
    }
}
