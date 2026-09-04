<?php

/**
 * Ενώνει διπλογραμμένα προγράμματα και κλειδώνει τη μοναδικότητα στη βάση.
 *
 * ## Τι πήγε στραβά
 *
 * Το `SeedVoltonPlans` (0028) έγραψε και τα 23 προγράμματα της Volton **δύο
 * φορές**. Ο έλεγχος «υπάρχει ήδη αυτό το code;» υπήρχε μέσα στον βρόχο και
 * ήταν σωστός — απλώς δεν μπορεί να κερδίσει: το `MigrationRunner::run()`
 * καλείται σε κάθε αίτηση, και μετά από deploy οι πρώτες ταυτόχρονες αιτήσεις
 * το βλέπουν όλες εκκρεμές. Δύο διεργασίες κάνουν `SELECT COUNT` (βλέπουν και
 * οι δύο μηδέν) και μετά `INSERT`. Το transient lock του runner στενεύει το
 * παράθυρο αλλά, όπως λέει το ίδιο του το σχόλιο, δεν είναι αδιάβλητο κλείδωμα.
 *
 * Το αποτύπωμα στη βάση το επιβεβαίωσε: ζευγάρια με ίδιο `code`, ίδιο
 * `sort_order` και **διαδοχικά** ids (18,19 · 20,21 · …) — δύο διεργασίες σε
 * lockstep, σειριοποιημένες ανά insert από την ίδια τη MySQL. Δύο διαδοχικές
 * εκτελέσεις θα είχαν αφήσει δύο ξεχωριστά μπλοκ ids, όχι ζευγάρια.
 *
 * Ο έλεγχος στον κώδικα μένει (είναι φθηνός και πιάνει την κανονική
 * επανεκτέλεση), αλλά από κάτω μπαίνει πια η βάση, που είναι το μόνο σημείο
 * που μπορεί να πει «όχι» σε δύο διεργασίες ταυτόχρονα.
 *
 * ## Γιατί δεν είναι σκέτο DELETE
 *
 * Τρεις πίνακες δείχνουν σε `programs.id`, και δύο από τους FK κανόνες κάνουν
 * ζημιά αθόρυβα: `contracts.program_id` είναι `ON DELETE SET NULL` (η σύμβαση
 * θα έχανε το πρόγραμμά της χωρίς να πει τίποτα) και
 * `commission_rules.program_id` είναι **`ON DELETE CASCADE`** — ένας κανόνας
 * προμήθειας γραμμένος πάνω στο «λάθος» αντίγραφο θα εξαφανιζόταν, και το
 * σύμπτωμα θα ήταν «κανείς δεν πληρώνεται», δηλαδή ακριβώς η σιωπηλή αποτυχία
 * που φυλάει το `CommissionRulesHealthTest`.
 *
 * Οι διπλές γραμμές είναι πανομοιότυπες — ίδιο code, όνομα, τιμή, κατηγορία —
 * οπότε η ανακατεύθυνση προς την επιζώσα δεν αλλάζει τίποτα εμπορικά. Επιζεί
 * το **μικρότερο id**, δηλαδή αυτή που γράφτηκε πρώτη.
 *
 * ## Εμβέλεια
 *
 * Καθαρίζει και κλειδώνει για **κάθε** πάροχο, όχι μόνο τη Volton. Τα
 * `SeedOrizonPlans` και `SeedProtergiaHomePlans` έχουν σήμερα ακριβώς το ίδιο
 * ρίσκο και απλώς δεν έτυχε να το πληρώσουν. Γραμμές χωρίς `code` (τα γενικά
 * starters των υπόλοιπων παρόχων) δεν θίγονται: η MySQL επιτρέπει πολλαπλά
 * `NULL` σε unique index, και εδώ αυτό είναι το ζητούμενο, όχι ανοχή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;
use RuntimeException;

final class DedupeProgramsByCode implements Migration
{
    private const INDEX = 'provider_code';

    /** Πίνακες που κρατούν `program_id` και πρέπει να ακολουθήσουν την επιζώσα. */
    private const REFERRERS = [
        Tables::CONTRACTS,
        Tables::COMMISSION_RULES,
        Tables::GUARANTEE_RULES,
    ];

    public function id(): string
    {
        return '0029_dedupe_programs_by_code';
    }

    public function description(): string
    {
        return 'Ένωση διπλογραμμένων προγραμμάτων ανά (provider_id, code)'
            . ' και UNIQUE index ώστε να μην ξαναγίνει';
    }

    public function apply(SchemaInspector $schema): void
    {
        $programs = Tables::name(Tables::PROGRAMS);

        if (! $schema->hasTable($programs) || ! $schema->hasColumn($programs, 'code')) {
            return;
        }

        $this->mergeDuplicates($schema, $programs);

        if (! $schema->hasIndex($programs, self::INDEX)) {
            $this->lockUniqueness($programs);
        }
    }

    private function mergeDuplicates(SchemaInspector $schema, string $programs): void
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
        $groups = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT provider_id, MIN(id) AS keep_id, GROUP_CONCAT(id) AS all_ids
                 FROM %i
                 WHERE code IS NOT NULL AND code <> ''
                 GROUP BY provider_id, code
                 HAVING COUNT(*) > 1",
                [$programs]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery

        foreach ((array) $groups as $group) {
            $keep = (int) $group->keep_id;
            $ids  = array_map('intval', explode(',', (string) $group->all_ids));
            $drop = array_values(array_filter($ids, static fn (int $id): bool => $id !== $keep));

            if ($drop === [] || $keep <= 0) {
                continue;
            }

            $this->repointReferrers($schema, $keep, $drop);
            $this->dropRows($programs, $drop);
        }
    }

    /**
     * @param list<int> $drop
     */
    private function repointReferrers(SchemaInspector $schema, int $keep, array $drop): void
    {
        global $wpdb;

        $slots = implode(',', array_fill(0, count($drop), '%d'));

        foreach (self::REFERRERS as $table) {
            $name = Tables::name($table);

            if (! $schema->hasTable($name) || ! $schema->hasColumn($name, 'program_id')) {
                continue;
            }

            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE %i SET program_id = %d WHERE program_id IN ({$slots})",
                    array_merge([$name, $keep], $drop)
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
        }
    }

    /**
     * @param list<int> $drop
     */
    private function dropRows(string $programs, array $drop): void
    {
        global $wpdb;

        $slots = implode(',', array_fill(0, count($drop), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE id IN ({$slots})",
                array_merge([$programs], $drop)
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
    }

    /**
     * Το μόνο σημείο που μπορεί να πει «όχι» σε δύο ταυτόχρονες διεργασίες.
     *
     * Αν το ALTER αποτύχει, το migration πετάει: ο runner δεν το καταγράφει ως
     * εφαρμοσμένο και η επόμενη αίτηση ξαναδοκιμάζει. Σιωπηλή αποτυχία εδώ θα
     * σήμαινε ότι το σύστημα νομίζει πως προστατεύεται ενώ δεν προστατεύεται.
     */
    private function lockUniqueness(string $programs): void
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery
        $done = $wpdb->query(
            $wpdb->prepare('ALTER TABLE %i ADD UNIQUE KEY provider_code (provider_id, code)', [$programs])
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.DirectDatabaseQuery

        if ($done === false) {
            throw new RuntimeException(
                'Το UNIQUE index provider_code δεν μπήκε -- πιθανόν έμειναν διπλές γραμμές: '
                . $wpdb->last_error
            );
        }
    }
}
