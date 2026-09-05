<?php

/**
 * Στήλες contracts.combo_mobile_mobile / combo_mobile_email — το κινητό και
 * το email του πελάτη ΚΙΝΗΤΗΣ στο COMBO, όταν η αίτηση ξεκινά από Volton και
 * ο πελάτης κινητής είναι άλλο πρόσωπο από τον πελάτη ενέργειας.
 *
 * ## Γιατί χρειάζεται δεύτερο ζεύγος στηλών, όχι επαναχρησιμοποίηση
 *
 * Το `AddComboEnergyContactColumns` (0030) πρόσθεσε το ίδιο ζεύγος για τον
 * πελάτη ΕΝΕΡΓΕΙΑΣ, όταν η αίτηση ξεκινά από Orizon -- εκεί ο κύριος πελάτης
 * της σύμβασης ΕΙΝΑΙ ο πελάτης κινητής, και «ο άλλος» χρειάζεται τη δική του
 * επαφή. Το Στάδιο 4 (05/09/2026) αντιστρέφει αυτό: το COMBO μπορεί να
 * ξεκινήσει από αίτηση Volton, όπου ο κύριος πελάτης της σύμβασης είναι ο
 * πελάτης ΕΝΕΡΓΕΙΑΣ, και «ο άλλος» — αν υπάρχει — είναι ο πελάτης κινητής.
 *
 * Οι δύο θέσεις υπογραφής στο χαρτί (mobile/energy) και το ζεύγος στηλών που
 * χρειάζεται η καθεμιά δεν αλλάζουν ποτέ ταυτόχρονα με ΠΟΙΟΣ είναι ο κύριος
 * πελάτης — γι' αυτό χρειάζονται ΔΥΟ ζεύγη στηλών, ένα ανά ρόλο, όχι ένα
 * γενικό «ο άλλος». Βλέπε `SignatureRoles::primaryRoleFor()`.
 *
 * ## Γιατί στήλες και όχι extra_json
 *
 * Ίδιο σκεπτικό με το (0030): θα χρησιμοποιηθούν άμεσα για τον σύνδεσμο
 * υπογραφής (`SignLinkController`) — business-rule σημασία από την πρώτη
 * μέρα, §1.17.
 *
 * ## Γιατί δεν είναι κρυπτογραφημένα
 *
 * Ίδιο επίπεδο ευαισθησίας και ίδια μεταχείριση με τα `combo_energy_mobile`/
 * `combo_energy_email` του (0030) — απλό κείμενο, όχι νέο μοτίβο.
 *
 * Μόνο η στήλη μπαίνει εδώ — η φόρμα, το `SignLinkController` και το
 * `ContractSaveMapping` που τη διαβάζουν/γράφουν είναι το ίδιο commit
 * («COMBO και από αίτηση Volton», Στάδιο 4), αλλά ξεχωριστό αρχείο, ίδιο
 * μοτίβο διαχωρισμού με το (0030)/(231).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddComboMobileContactColumns implements Migration
{
    public function id(): string
    {
        return '0032_add_combo_mobile_contact_columns';
    }

    public function description(): string
    {
        return 'Στήλες contracts.combo_mobile_mobile / combo_mobile_email --'
            . ' επικοινωνία με τον πελάτη κινητής στο COMBO, όταν η αίτηση ξεκινά από Volton';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CONTRACTS);

        if (! $schema->hasTable($table)) {
            return;
        }

        // Ίδιο πλάτος με τα combo_energy_mobile / combo_energy_email (0030).
        if (! $schema->hasColumn($table, 'combo_mobile_mobile')) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `combo_mobile_mobile` VARCHAR(40) NULL");
        }

        if (! $schema->hasColumn($table, 'combo_mobile_email')) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `combo_mobile_email` VARCHAR(160) NULL");
        }
    }
}
