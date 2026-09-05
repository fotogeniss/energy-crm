<?php

/**
 * Πίνακας customer_notes -- ελεύθερο κείμενο ΓΙΑ έναν πελάτη (247, Στάδιο 2).
 *
 * Ίδια δικαιολογία με το `assistant_messages`/`events`: ένα ξεχωριστός
 * πίνακας, όχι στήλη στο `customers`, γιατί μπορούν να υπάρχουν πολλές
 * σημειώσεις από πολλούς συνεργάτες, το καθεμιά με δικό της συντάκτη και
 * χρόνο -- σε μια στήλη TEXT θα χανόταν το "ποιος και πότε" και δύο
 * συνεργάτες θα έσβηναν ο ένας τον άλλο.
 *
 * `customer_id`, ΟΧΙ `contract_id`: η σημείωση αφορά τον άνθρωπο, όχι μία
 * συγκεκριμένη αίτησή του. Αυτό κάνει την ακμή `customer_notes.customer_id`
 * μια ΔΕΥΤΕΡΗ ακμή-προς-πρόσωπο που δεν χωράει στο
 * `PersonalDataTables::linkedToContracts()` (κλειδωμένο σε contract_id) --
 * χειρίζεται ρητά, με το χέρι, στο `PersonalDataExporter`/`PersonalDataEraser`
 * και δηλώνεται στο `PersonalDataCoverageTest::HANDLED_INLINE`. Ιδια
 * κατηγορία με το `tasks.customer_id` που ήδη υπάρχει εκεί.
 *
 * `CREATE TABLE` απευθείας με `$wpdb->query()`, όχι `dbDelta()`: το
 * `dbDelta()` χρειάζεται WordPress upgrade helpers που δεν είναι πάντα
 * φορτωμένα εδώ, και το ίδιο μοτίβο (`$wpdb->query("ALTER TABLE ...")`) ήδη
 * χρησιμοποιούν όλα τα υπόλοιπα migrations αυτού του φακέλου για DDL. Ο
 * πίνακας ΕΠΙΣΗΣ μπαίνει στο `dbDelta()` του `includes/class-ecrm-db.php`
 * (νέα εγκατάσταση) -- εκεί τον διαβάζει και ο `PersonalDataCoverageTest`,
 * που σαρώνει το ΣΧΗΜΑ από εκείνο ακριβώς το αρχείο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class CreateCustomerNotesTable implements Migration
{
    public function id(): string
    {
        return '0034_create_customer_notes_table';
    }

    public function description(): string
    {
        return 'Νέος πίνακας customer_notes -- σημειώσεις πελάτη, εκτός τυπωμένων εντύπων (247)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CUSTOMER_NOTES);

        if ($schema->hasTable($table)) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        // Ιδιο σχήμα με το dbDelta() του includes/class-ecrm-db.php -- δες
        // εκεί για το γιατί ΔΕΝ χρησιμοποιείται εδώ το ίδιο dbDelta(). Το
        // phpcs:ignore σε μία γραμμή δεν αρκεί εδώ -- η παράθεση απλώνεται σε
        // πολλές γραμμές, άρα disable/enable γύρω από ολόκληρη την κλήση.
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query(
            "CREATE TABLE `{$table}` (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                customer_id     BIGINT UNSIGNED NOT NULL,
                partner_user_id BIGINT UNSIGNED NULL,
                body            TEXT NOT NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY customer_id (customer_id)
            ) {$charset}"
        );
        // phpcs:enable WordPress.DB.PreparedSQL
    }
}
