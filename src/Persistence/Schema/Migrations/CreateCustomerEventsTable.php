<?php

/**
 * Πίνακας customer_events -- ιστορικό αλλαγών στοιχείων πελάτη (247, Στάδιο 3).
 *
 * Μία γραμμή ανά ΠΕΔΙΟ που άλλαξε σε ένα save (field/old_value/new_value),
 * όχι μία γραμμή ανά save με όλα μαζωμένα -- ίδιο σκεπτικό με το
 * ContractEvent για status_change: "ποιος άλλαξε ΤΙ και πότε" απαντιέται με
 * ένα SELECT, όχι με parse ενός JSON blob κάθε φορά που ζητιέται.
 *
 * `customer_id`, ΟΧΙ `contract_id`: η αλλαγή αφορά τον άνθρωπο, όχι μία
 * συγκεκριμένη αίτησή του -- ίδια ΔΕΥΤΕΡΗ ακμή-προς-πρόσωπο με το
 * `customer_notes.customer_id`, εκτός `PersonalDataTables::linkedToContracts()`
 * (κλειδωμένο σε contract_id), χειρίζεται ρητά στο
 * `PersonalDataExporter`/`PersonalDataEraser` και δηλώνεται στο
 * `PersonalDataCoverageTest::HANDLED_INLINE`.
 *
 * `CREATE TABLE` απευθείας με `$wpdb->query()`, ίδιο μοτίβο με το
 * `CreateCustomerNotesTable` -- δες εκεί για το γιατί όχι `dbDelta()` εδώ.
 * Ο πίνακας ΕΠΙΣΗΣ μπαίνει στο `dbDelta()` του `includes/class-ecrm-db.php`
 * (νέα εγκατάσταση), που διαβάζει και ο `PersonalDataCoverageTest`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class CreateCustomerEventsTable implements Migration
{
    public function id(): string
    {
        return '0035_create_customer_events_table';
    }

    public function description(): string
    {
        return 'Νέος πίνακας customer_events -- ιστορικό αλλαγών στοιχείων πελάτη (247, Στάδιο 3)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CUSTOMER_EVENTS);

        if ($schema->hasTable($table)) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query(
            "CREATE TABLE `{$table}` (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id     BIGINT UNSIGNED NOT NULL,
                partner_user_id BIGINT UNSIGNED NULL,
                field           VARCHAR(40) NOT NULL,
                old_value       TEXT NULL,
                new_value       TEXT NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY customer_id (customer_id)
            ) {$charset}"
        );
        // phpcs:enable WordPress.DB.PreparedSQL
    }
}
