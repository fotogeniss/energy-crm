<?php

/**
 * Οι στήλες που χρειάζεται ο δημόσιος «σύνδεσμός μου».
 *
 * Τρεις στήλες, δύο πίνακες:
 *
 * `files.lead_id` — ο πελάτης ανεβάζει έγγραφα ΠΡΙΝ υπάρξει σύμβαση, οπότε το
 * αρχείο κρέμεται από τον υποψήφιο. Το `contract_id` ήταν ήδη nullable, άρα
 * αρχείο χωρίς σύμβαση ήταν ήδη νόμιμη κατάσταση· έλειπε μόνο το πού ανήκει
 * στο ενδιάμεσο. Στη μετατροπή γεμίζει το `contract_id` και το `lead_id`
 * **μένει**: η προέλευση δεν σβήνεται, ώστε να φαίνεται ποια έγγραφα τα έφερε
 * ο ίδιος ο πελάτης και ποια ο πωλητής.
 *
 * `leads.consent_at` / `leads.consent_ip` — ιδιώτης στέλνει ταυτότητα μέσα από
 * δημόσια σελίδα. Η συναίνεση είναι νομικό τεκμήριο, όχι checkbox, οπότε
 * παίρνει δικές της στήλες αντί να χωθεί ως πρόταση στο `notes`. Ίδια ονόματα
 * και ίδιο πλάτος με τα `contracts.consent_at`/`consent_ip` που υπάρχουν ήδη —
 * μία έννοια, μία γραφή.
 *
 * Νέο migration και όχι επέκταση του `EnsureLegacyColumns`: εκείνο έχει ήδη
 * τρέξει και καταγραφεί στα ζωντανά site, και ο runner δεν ξαναπερνά id που
 * έχει σημειωθεί. Προσθήκη εκεί θα εφαρμοζόταν μόνο σε καθαρή εγκατάσταση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddIntakeColumns implements Migration
{
    public function id(): string
    {
        return '0021_add_intake_columns';
    }

    public function description(): string
    {
        return 'files.lead_id και leads.consent_at/consent_ip — για τον δημόσιο σύνδεσμο υποψηφίων';
    }

    public function apply(SchemaInspector $schema): void
    {
        $this->addColumn($schema, Tables::name(Tables::FILES), 'lead_id', 'BIGINT UNSIGNED NULL');
        $this->addIndex($schema, Tables::name(Tables::FILES), 'lead_id');
        $this->addColumn($schema, Tables::name(Tables::LEADS), 'consent_at', 'DATETIME NULL');
        $this->addColumn($schema, Tables::name(Tables::LEADS), 'consent_ip', 'VARCHAR(64) NULL');
    }

    private function addColumn(SchemaInspector $schema, string $table, string $column, string $definition): void
    {
        global $wpdb;

        if (! $schema->hasTable($table) || $schema->hasColumn($table, $column)) {
            return;
        }

        // Fixed identifiers, closed list; DDL cannot be parameterised.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }

    /**
     * Το ευρετήριο μπαίνει χωριστά και ανεκτικά.
     *
     * Σε καθαρή εγκατάσταση το dbDelta το έχει ήδη φτιάξει από το CREATE TABLE,
     * οπότε το ALTER θα απέτυχε. Ένα migration που πετάει δεν καταγράφεται και
     * ξαναδοκιμάζει σε κάθε αίτηση για πάντα — γι' αυτό ελέγχεται πρώτα.
     */
    private function addIndex(SchemaInspector $schema, string $table, string $column): void
    {
        global $wpdb;

        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, $column)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL
        $existing = $wpdb->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$column}'");

        if (! empty($existing)) {
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("ALTER TABLE `{$table}` ADD KEY `{$column}` (`{$column}`)");
    }
}
