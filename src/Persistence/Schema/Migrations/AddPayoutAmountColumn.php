<?php

/**
 * Το ποσό με το οποίο μπήκε η κάθε σύμβαση στην εκκαθάρισή της.
 *
 * Η παρτίδα κρατούσε μόνο σύνολο (`payouts.amount`). Η οθόνη προμηθειών όμως
 * δείχνει γραμμή ανά σύμβαση και υπολόγιζε **ζωντανά**, οπότε μια αλλαγή στους
 * κανόνες προμήθειας μετά τη δημιουργία της παρτίδας έκανε τις γραμμές να
 * διαφωνούν με το σύνολο που πληρώθηκε — μόνιμα και χωρίς καμία ένδειξη.
 *
 * Ο ιδιοκτήτης αποφάσισε (18/08/2026) ότι το ποσό είναι **στιγμιότυπο**:
 * πληρωμένη εκκαθάριση δεν αλλάζει αναδρομικά. Για να μπορεί η οθόνη να το
 * δείξει, το στιγμιότυπο πρέπει να υπάρχει ανά σύμβαση, όχι μόνο ανά παρτίδα.
 *
 * Δίπλα στο `payout_id`, γιατί λέει το ίδιο πράγμα για την ίδια σχέση: σε ποια
 * παρτίδα μπήκε, και με πόσα. Χωριστός πίνακας `payout_items` θα ήταν πιο
 * κανονικοποιημένος, αλλά η σχέση είναι ήδη 1-προς-1 και ζει σε αυτή τη στήλη
 * εδώ και καιρό· δύο σημεία για την ίδια σχέση είναι χειρότερα από μία στήλη
 * παραπάνω.
 *
 * NULL σημαίνει «δεν έχει σφραγιστεί σε παρτίδα» — και επιπλέον, για γραμμές
 * σφραγισμένες πριν από αυτή τη μετάβαση, «δεν κρατήθηκε στιγμιότυπο». Και στις
 * δύο περιπτώσεις η οθόνη πέφτει στον ζωντανό υπολογισμό, δηλαδή στη σημερινή
 * συμπεριφορά. Δεν γεμίζει αναδρομικά: ο υπολογισμός σήμερα δεν είναι ο
 * υπολογισμός της ημέρας που φτιάχτηκε η παρτίδα, οπότε ένα backfill θα έγραφε
 * ως «στιγμιότυπο» έναν αριθμό που δεν υπήρξε ποτέ. Η βάση δεν έχει καμία
 * πραγματική εκκαθάριση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddPayoutAmountColumn implements Migration
{
    public function id(): string
    {
        return '0016_add_payout_amount_column';
    }

    public function description(): string
    {
        return 'Στήλη contracts.payout_amount — το ποσό με το οποίο μπήκε η σύμβαση στην εκκαθάριση';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::CONTRACTS);

        if (! $schema->hasTable($table) || $schema->hasColumn($table, 'payout_amount')) {
            return;
        }

        // Fixed identifier, closed list; DDL cannot be parameterised. Uses
        // disable/enable (not a single-line ignore) because the query spans
        // multiple lines and phpcs only suppresses the one line right after
        // an ignore comment -- a single-line ignore here silently stopped
        // covering the actual violation once the call wrapped.
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query(
            "ALTER TABLE `{$table}` ADD COLUMN `payout_amount` DECIMAL(10,2) NULL AFTER `payout_id`"
        );
        // phpcs:enable WordPress.DB.PreparedSQL
    }
}
