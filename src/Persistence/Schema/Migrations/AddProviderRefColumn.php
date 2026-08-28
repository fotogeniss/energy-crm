<?php

/**
 * Δεύτερο κλειδί ταιριάσματος — η θέση όπου θα μπει ο αριθμός αίτησης του
 * παρόχου όταν έρθει integration (§1.13).
 *
 * ## Το πρόβλημα που δεν λύνει ακόμα
 *
 * Η `ECRM_Import::apply()` ταιριάζει αποκλειστικά με `supply_number`. Ο
 * αριθμός παροχής (ΗΚΑΣΠ) ανήκει στο σημείο κατανάλωσης, όχι στη σύμβαση —
 * γι' αυτό υπάρχει καν το `unmatched`: γραμμή του παρόχου που δεν βρίσκει
 * ταίριασμα σήμερα, συχνά επειδή ο πάροχος αναφέρεται στη ΔΙΚΗ ΤΟΥ αίτηση,
 * όχι στο ΗΚΑΣΠ. Ένα δεύτερο κλειδί (ο αριθμός αίτησης του παρόχου) θα
 * επέτρεπε ταίριασμα και όταν λείπει ή αλλάζει ο πρώτος.
 *
 * ## Γιατί μπαίνει η στήλη τώρα, χωρίς τίποτα να τη γεμίζει
 *
 * Απόφαση ιδιοκτήτη 28/08/2026: μόνο η στήλη σήμερα, καμία σύνδεση με το
 * import ή νέο πεδίο στην οθόνη — αυτό θα ήταν ορατή αλλαγή UI και θέλει
 * μακέτα (§1.8) δική της, σε δικό της commit. Η στήλη από μόνη της δεν αλλάζει
 * καμία συμπεριφορά: `NULL` σε κάθε υπάρχουσα γραμμή, καμία στήλη writable
 * δεν την αγγίζει (βλ. `WritableColumns` — δεν προστέθηκε εκεί σκόπιμα).
 *
 * Αξίζει να μπει νωρίς ακριβώς επειδή **δεν συμπληρώνεται αναδρομικά**: ο
 * αριθμός αίτησης του παρόχου για συμβάσεις που ήδη υπάρχουν δεν είναι
 * πουθενά αλλού καταγεγραμμένος στο CRM. Μια στήλη που προστίθεται αργότερα
 * ξεκινά άδεια για το παρελθόν είτε προστεθεί σήμερα είτε σε έναν χρόνο — η
 * μόνη διαφορά είναι πόσες μελλοντικές συμβάσεις προλαβαίνουν να τη γεμίσουν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddProviderRefColumn implements Migration
{
    public function id(): string
    {
        return '0024_add_provider_ref_column';
    }

    public function description(): string
    {
        return 'Στήλη contracts.provider_ref — δεύτερο κλειδί ταιριάσματος για μελλοντικό integration παρόχου';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CONTRACTS);

        if (! $schema->hasTable($table) || $schema->hasColumn($table, 'provider_ref')) {
            return;
        }

        // Fixed identifier, closed list; DDL cannot be parameterised. Ίδιο
        // πλάτος με το supply_number/meter_number -- ίδια οικογένεια αριθμών
        // αναφοράς σε σύμβαση.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `provider_ref` VARCHAR(40) NULL");
    }
}
