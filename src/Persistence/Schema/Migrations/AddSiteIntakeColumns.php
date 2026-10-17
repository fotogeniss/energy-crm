<?php

/**
 * Οι στήλες που χρειάζονται οι αιτήσεις από το site σύγκρισης τιμών.
 *
 * `leads.external_ref` — το αναγνωριστικό της αίτησης στο site. Είναι το
 * «σταθερό κλειδί συσχέτισης» του ARCHITECTURE («Ετοιμότητα για
 * integrations», ερώτημα 1): το site ξαναστέλνει την ίδια αίτηση όσες φορές
 * χρειαστεί (δίκτυο, timeout) και το CRM βρίσκει τον ΙΔΙΟ υποψήφιο αντί να
 * φτιάξει δεύτερο. UNIQUE, για να το εγγυάται η βάση και όχι ένα SELECT πριν
 * το INSERT που χάνει σε ταυτόχρονα αιτήματα. NULL σε όλους τους άλλους
 * υποψηφίους — πολλά NULL σε unique index επιτρέπονται.
 *
 * `leads.site_data` — ΑΦΜ, διεύθυνση, αριθμός παροχής, κατηγορία, πρόγραμμα,
 * κρυπτογραφημένα (FieldCipher). Τα διαβάζει μόνο η «Μετατροπή», που τα
 * περνά σε πελάτη/σύμβαση και μετά τα σβήνει από εδώ.
 *
 * Το id ΔΕΝ είναι `0043_add_web_intake_columns`: αυτό το όνομα το πήρε μια
 * αλλαγή που δοκιμάστηκε και αποσύρθηκε (12/10/2026) και μπορεί να είναι ήδη
 * καταγεγραμμένο σε κάποια βάση. Ίδιο id θα σήμαινε ότι εκεί αυτό εδώ δεν θα
 * έτρεχε ποτέ.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddSiteIntakeColumns implements Migration
{
    public function id(): string
    {
        return '0043_add_site_intake_columns';
    }

    public function description(): string
    {
        return 'leads.external_ref (μοναδικό) και leads.site_data — αιτήσεις από το site σύγκρισης τιμών';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::LEADS);

        if (! $schema->hasTable($table)) {
            return;
        }

        if (! $schema->hasColumn($table, 'external_ref')) {
            // Fixed identifiers; DDL cannot be parameterised.
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `external_ref` VARCHAR(64) NULL");
        }

        if (! $schema->hasColumn($table, 'site_data')) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `site_data` LONGTEXT NULL");
        }

        if ($schema->hasColumn($table, 'external_ref') && ! $schema->hasIndex($table, 'external_ref')) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `external_ref` (`external_ref`)");
        }
    }
}
