<?php

/**
 * Στήλη files.expires_at — η ημερομηνία λήξης που είναι ήδη τυπωμένη πάνω
 * σε μια ταυτότητα ή διαβατήριο.
 *
 * ## Το κενό που καλύπτει
 *
 * Τρίτο από τα τρία ευρήματα του ελέγχου αυτοματοποίησης (31/08/2026): το
 * `ECRM_Docs::checklist()` ελέγχει μόνο ΠΑΡΟΥΣΙΑ ενός `doc_kind`, ποτέ
 * ΕΓΚΥΡΟΤΗΤΑ. Μια ταυτότητα που ανέβηκε σωστά αλλά έχει λήξει εδώ και μήνες
 * περνά το ίδιο check με μια φρέσκια -- δεν υπήρχε καμία στήλη να κρατήσει
 * τη διαφορά (`files` schema, `class-ecrm-db.php`, χωρίς `expires_at`/
 * `verified_at`).
 *
 * ## Γιατί μόνο αυτή η στήλη, όχι ξεχωριστός πίνακας «verifications»
 *
 * Η λήξη μιας ταυτότητας/διαβατηρίου είναι ΤΥΠΩΜΕΝΗ πάνω στο ίδιο το
 * έγγραφο -- δεν είναι απόφαση κάποιου να «επαληθεύσει», είναι δεδομένο
 * που ο συνεργάτης απλώς αντιγράφει στη φόρμα την ώρα του ανεβάσματος. Ένας
 * πίνακας «verifications» θα υπονοούσε ροή έγκρισης που δεν υπάρχει ακόμα
 * και δεν ζητήθηκε. Μία στήλη, όπως το `payout_amount` (0016) και το
 * `track_key` (0017) πριν από αυτήν.
 *
 * ## Γιατί δεν κρατά ΠΟΙΑ είδη εγγράφου τη χρησιμοποιούν
 *
 * Το `files` δεν διαφοροποιεί ΠΟΤΕ στήλες ανά `doc_kind` -- το `doc_kind`
 * είναι ήδη η στήλη που το λέει αυτό. Ποιοι τύποι έχουν νόημα να τη
 * γεμίσουν (σήμερα: μόνο `id_card`) είναι απόφαση εφαρμογής
 * (`ECRM_Docs::expirable_kinds()`), όχι σχήματος -- το ίδιο μοτίβο με το
 * πώς το `required_map()` δεν είναι κωδικοποιημένο στη βάση.
 *
 * NULL σημαίνει «δεν καταγράφηκε λήξη» -- είτε επειδή το έγγραφο δεν είναι
 * τύπου που λήγει, είτε επειδή ανέβηκε πριν από αυτή τη μετάβαση. Δεν
 * γίνεται backfill: δεν υπάρχει τρόπος να μαντέψουμε τη λήξη μιας ήδη
 * σαρωμένης ταυτότητας χωρίς να την ξανακοιτάξει άνθρωπος.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddFileExpiryColumn implements Migration
{
    public function id(): string
    {
        return '0026_add_file_expiry_column';
    }

    public function description(): string
    {
        return 'Στήλη files.expires_at — η τυπωμένη ημερομηνία λήξης ενός εγγράφου (π.χ. ταυτότητα)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::FILES);

        if (! $schema->hasTable($table) || $schema->hasColumn($table, 'expires_at')) {
            return;
        }

        // Fixed identifier, closed list; DDL cannot be parameterised.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `expires_at` DATE NULL AFTER `doc_kind`");
    }
}
