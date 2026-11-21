<?php

/**
 * (440) Στήλη files.uploaded_via: από πού ανέβηκε ένα αρχείο.
 *
 * Το όριο αρχείων του συνδέσμου του πελάτη μετρούσε ΟΛΑ τα αρχεία της αίτησης,
 * και του συνεργάτη και το υπογεγραμμένο PDF. Απόφαση ιδιοκτήτη 21/11: 10
 * αρχεία, και μόνο όσα ανέβασε ο ίδιος ο πελάτης από τον σύνδεσμο
 * (`ECRM_Tracking::VIA_LINK`).
 *
 * Χωρίς backfill: NULL στα παλιά σημαίνει «δεν ξέρουμε», και τα παλιά δεν
 * μετρούν. Ο πελάτης κερδίζει το πολύ λίγα παραπάνω αρχεία, μία φορά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddFileUploadedViaColumn implements Migration
{
    public function id(): string
    {
        return '0066_add_file_uploaded_via_column';
    }

    public function description(): string
    {
        return 'Στήλη files.uploaded_via — τι ανέβασε ο πελάτης από τον σύνδεσμο';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::FILES);

        if (! $schema->hasTable($table) || $schema->hasColumn($table, 'uploaded_via')) {
            return;
        }

        // Fixed identifier, closed list; DDL cannot be parameterised.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `uploaded_via` VARCHAR(16) NULL AFTER `kind_before`");
    }
}
