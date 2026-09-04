<?php

/**
 * Στήλες contracts.combo_energy_mobile / combo_energy_email — το κινητό και
 * το email του πελάτη ενέργειας στο COMBO, όταν είναι άλλο πρόσωπο από τον
 * πελάτη κινητής.
 *
 * ## Γιατί στήλες και όχι extra_json
 *
 * Το `combo_energy_name/afm/adt/doy` μένουν σκόπιμα σε `extra_json` -- είναι
 * στοιχεία που μόνο τυπώνονται στο χαρτί, καμία άλλη χρήση (βλ.
 * UI-COMBO-ENERGY-CUSTOMER.html). Το κινητό/email εδώ είναι διαφορετικά: θα
 * χρησιμοποιηθούν άμεσα για να χτιστεί ο δεύτερος σύνδεσμος υπογραφής (3β-Β)
 * -- business-rule σημασία από την πρώτη μέρα. Ο κανόνας §1.17 λέει ρητά ότι
 * τέτοιο πεδίο μπαίνει κατευθείαν σε στήλη, όχι στο extra_json με σκοπό να
 * "βγει" αργότερα.
 *
 * ## Γιατί δεν είναι κρυπτογραφημένα
 *
 * Ίδιο επίπεδο ευαισθησίας και ίδια μεταχείριση με τα υπάρχοντα
 * `customers.mobile` / `customers.email` του κύριου πελάτη -- εκείνα είναι
 * ήδη απλό κείμενο (μόνο το `customers.phone`, το σταθερό τηλέφωνο, είναι
 * κρυπτογραφημένο, με blind index `phone_hash`). Το κινητό/email του πελάτη
 * ενέργειας ακολουθεί το ίδιο μοτίβο, όχι νέο.
 *
 * Μόνο η στήλη μπαίνει εδώ -- η φόρμα, το SignLinkController και το
 * rest_sign() που τη διαβάζουν είναι ξεχωριστό commit.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddComboEnergyContactColumns implements Migration
{
    public function id(): string
    {
        return '0030_add_combo_energy_contact_columns';
    }

    public function description(): string
    {
        return 'Στήλες contracts.combo_energy_mobile / combo_energy_email --'
            . ' επικοινωνία με τον πελάτη ενέργειας στο COMBO, για τον δεύτερο σύνδεσμο υπογραφής';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CONTRACTS);

        if (! $schema->hasTable($table)) {
            return;
        }

        // Fixed identifiers, closed list; DDL cannot be parameterised. Ίδιο
        // πλάτος με το customers.mobile / customers.email αντίστοιχα.
        if (! $schema->hasColumn($table, 'combo_energy_mobile')) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `combo_energy_mobile` VARCHAR(40) NULL");
        }

        if (! $schema->hasColumn($table, 'combo_energy_email')) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `combo_energy_email` VARCHAR(160) NULL");
        }
    }
}
