<?php

/**
 * Στήλη `providers.status_map` — το λεξιλόγιο καταστάσεων κάθε παρόχου.
 *
 * Ο χάρτης «κατάσταση παρόχου → δική μας» χτιζόταν από την αρχή σε κάθε
 * εισαγωγή Excel, σε μεταβλητή του browser που πέθαινε με τη σελίδα. Δέκα
 * πάροχοι, ο καθένας με δικό του λεξιλόγιο, και ο ίδιος κόπος κάθε φορά.
 *
 * JSON σε στήλη και όχι δικός του πίνακας (απόφαση ιδιοκτήτη, 27/08/2026):
 * δεν ερωτάται ποτέ με SQL — διαβάζεται ολόκληρος όταν ανοίγει η οθόνη και
 * γράφεται ολόκληρος όταν κλείνει. Ξεχωριστός πίνακας θα έφερνε κλειδιά, join
 * και δική του οθόνη διαχείρισης για δεδομένα που κανείς δεν βλέπει έξω από τη
 * ροή που τα παράγει.
 *
 * `LONGTEXT NULL`: `NULL` σημαίνει «δεν έχει αποφασίσει ποτέ κανείς», που
 * είναι διαφορετικό από `{}` — «τα κοίταξε και δεν κράτησε τίποτα». Η οθόνη
 * δείχνει άλλο μήνυμα στις δύο περιπτώσεις.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddProviderStatusMapColumn implements Migration
{
    public function id(): string
    {
        return '0022_add_provider_status_map_column';
    }

    public function description(): string
    {
        return 'Στήλη providers.status_map — αποθηκευμένη αντιστοίχιση καταστάσεων ανά πάροχο';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::PROVIDERS);

        if (! $schema->hasTable($table)) {
            return;
        }

        if ($schema->hasColumn($table, 'status_map')) {
            return;
        }

        // Σταθερό αναγνωριστικό, κλειστή λίστα· το DDL δεν παραμετροποιείται.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `status_map` LONGTEXT NULL AFTER `sort_order`");
    }
}
