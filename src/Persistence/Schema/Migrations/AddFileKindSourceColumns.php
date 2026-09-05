<?php

/**
 * Στήλες files.kind_source / files.kind_before — ποιος αποφάσισε την ετικέτα
 * ενός εγγράφου, και τι ήταν πριν.
 *
 * ## Το κενό που καλύπτει
 *
 * Ο `doc_kind` έλεγε ΤΙ είναι το έγγραφο, ποτέ ΠΟΙΟΣ το είπε. Οσο την ετικέτα
 * την έβαζε μόνο άνθρωπος, δεν χρειαζόταν: η απάντηση ήταν πάντα «ο συνεργάτης
 * που το ανέβασε». Από τη στιγμή που η ίδια στήλη μπορεί να αλλάξει και από
 * αυτόματη ανάγνωση, το «ποιος» γίνεται πραγματική ερώτηση -- και σε φάκελο με
 * σαρωμένες ταυτότητες μια ετικέτα που άλλαξε χωρίς να ξέρει κανείς ποιος την
 * άλλαξε δεν είναι λεπτομέρεια.
 *
 * ## Γιατί δύο στήλες και όχι μία
 *
 * Απαντούν σε δύο διαφορετικά ερωτήματα που τυχαίνει να αφορούν το ίδιο πεδίο:
 *
 * - `kind_source` -- «να ξανακοιτάξω αυτό το αρχείο;». NULL σημαίνει «δεν το
 *   έκρινε ποτέ κανείς»· `ai`/`ai_ok` σημαίνει «διαβάστηκε, μη ξαναπληρώσεις»·
 *   `human` σημαίνει «ο άνθρωπος αποφάσισε, μην το αγγίξεις ποτέ αυτόματα».
 * - `kind_before` -- «τι επαναφέρω αν πατήσει Αναίρεση». Χωρίς αυτήν, η
 *   αναίρεση θα ήταν εικασία: το `doc_kind` έχει ήδη αντικατασταθεί, και η
 *   προηγούμενη τιμή δεν υπάρχει πουθενά αλλού.
 *
 * Οι τιμές τους ζουν στο `Domain\Document\KindVerdict` ως σταθερές, όχι εδώ --
 * ίδιο μοτίβο με το `expires_at` (0026), όπου το ΠΟΙΑ είδη λήγουν είναι απόφαση
 * εφαρμογής (`ECRM_Docs::expirable_kinds()`) και όχι σχήματος.
 *
 * ## Γιατί δεν γίνεται backfill
 *
 * NULL σε παλιά αρχεία σημαίνει «δεν το έκρινε ποτέ κανείς», που είναι ακριβώς
 * η αλήθεια: ανέβηκαν πριν υπάρξει ανάγνωση. Θα διαβαστούν την πρώτη φορά που
 * θα ανοίξει η καρτέλα τους, μία φορά, και μετά θα σημειωθούν -- χωρίς να
 * χρειαστεί μαζική ανάγνωση ολόκληρου του αρχείου εγγράφων σε μία μετάβαση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddFileKindSourceColumns implements Migration
{
    public function id(): string
    {
        return '0031_add_file_kind_source_columns';
    }

    public function description(): string
    {
        return 'Στήλες files.kind_source / files.kind_before — ποιος αποφάσισε το είδος του εγγράφου';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::FILES);

        if (! $schema->hasTable($table)) {
            return;
        }

        if (! $schema->hasColumn($table, 'kind_source')) {
            // Fixed identifier, closed list; DDL cannot be parameterised.
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `kind_source` VARCHAR(16) NULL AFTER `doc_kind`");
        }

        if (! $schema->hasColumn($table, 'kind_before')) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `kind_before` VARCHAR(24) NULL AFTER `kind_source`");
        }
    }
}
