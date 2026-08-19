<?php

/**
 * Δικό της κλειδί κάθε σύμβαση, ώστε ο σύνδεσμος παρακολούθησης να ανακαλείται.
 *
 * Το token του πελάτη ήταν `{id}-{hmac(id, wp_salt('auth'))}`: καθαρή
 * κατασκευή, 80 bits, χωρίς εγγραφή στη βάση, μη απαριθμήσιμο — και
 * **ντετερμινιστικό και αιώνιο**. Ο ίδιος σύνδεσμος για την ίδια σύμβαση, για
 * πάντα. Το SMS του Ιανουαρίου ανοίγει τον Δεκέμβριο.
 *
 * Το πρόβλημα δεν ήταν η ισχύς του, ήταν ότι δεν ανακαλείται. Κοινό οικογενειακό
 * κινητό, προωθημένο μήνυμα, screenshot — και ο μόνος τρόπος να πάψει να ισχύει
 * **αυτός** ο σύνδεσμος ήταν να αλλάξει το `wp_salt('auth')`, που ακυρώνει
 * **όλους** τους συνδέσμους και πετάει έξω κάθε συνδεδεμένο χρήστη του
 * WordPress. Έλεγχος λειτουργίας 18/08/2026, εύρημα 6.
 *
 * Με το κλειδί μέσα στο υλικό του HMAC, η ανάκληση γίνεται μία γραμμή: νέο
 * κλειδί, ο παλιός σύνδεσμος πεθαίνει, όλοι οι άλλοι ζουν.
 *
 * NULL σημαίνει «δεν έχει ζητηθεί ποτέ σύνδεσμος γι' αυτήν». Το κλειδί
 * παράγεται την πρώτη φορά που χτίζεται σύνδεσμος, όχι εδώ: ένα backfill θα
 * γέμιζε με κλειδιά χιλιάδες συμβάσεις που κανείς δεν πρόκειται να μοιραστεί.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddTrackKeyColumn implements Migration
{
    public function id(): string
    {
        return '0017_add_track_key_column';
    }

    public function description(): string
    {
        return 'Στήλη contracts.track_key — κάνει τον σύνδεσμο παρακολούθησης ανακλητό ανά σύμβαση';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CONTRACTS);

        if (! $schema->hasTable($table) || $schema->hasColumn($table, 'track_key')) {
            return;
        }

        // Fixed identifier, closed list; DDL cannot be parameterised.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `track_key` VARCHAR(32) NULL");
    }
}
