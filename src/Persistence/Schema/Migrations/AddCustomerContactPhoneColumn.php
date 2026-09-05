<?php

/**
 * Στήλη customers.contact_phone -- τηλέφωνο εσωτερικής χρήσης (247, Στάδιο 2).
 *
 * Δεν είναι το τηλέφωνο του πελάτη (`phone`/`mobile`) -- είναι ένα δεύτερο,
 * προαιρετικό νούμερο επικοινωνίας που ζητά η αίτηση σε κάποιες περιπτώσεις
 * αλλά η καρτέλα πελάτη δεν το είχε πουθενά, οπότε ο συνεργάτης έψαχνε ξανά
 * το χαρτί κάθε φορά. Ζει μόνο στην καρτέλα, ποτέ σε τυπωμένο έντυπο.
 *
 * Κρυπτογραφημένη, όπως το phone: προσωπικό δεδομένο. VARCHAR(255) από την
 * πρώτη μέρα -- όχι VARCHAR(40) που θα χρειαζόταν δικό του
 * WidenCustomerPhoneColumn αργότερα, ίδιο μάθημα με το (0019).
 *
 * Χωρίς δικό της hash: δεν χρησιμοποιείται για αναζήτηση ή έλεγχο
 * διπλοεγγραφής -- μόνο εμφανίζεται στην καρτέλα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddCustomerContactPhoneColumn implements Migration
{
    public function id(): string
    {
        return '0033_add_customer_contact_phone_column';
    }

    public function description(): string
    {
        return 'Στήλη customers.contact_phone -- τηλέφωνο εσωτερικής χρήσης, κρυπτογραφημένο (247)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CUSTOMERS);

        if (! $schema->hasTable($table) || $schema->hasColumn($table, 'contact_phone')) {
            return;
        }

        // Identifiers come from the closed set above; no request data
        // reaches this statement, and DDL cannot be parameterised.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `contact_phone` VARCHAR(255) NULL");
    }
}
