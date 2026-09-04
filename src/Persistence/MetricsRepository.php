<?php

/**
 * Ιστορικό λειτουργίας: πόσες φορές συνέβη κάτι, ανά ημέρα.
 *
 * ## Γιατί χρειάστηκε πίνακας και δεν έφτανε ένα option
 *
 * Πριν από αυτό, το plugin ήξερε μόνο τη ΣΤΙΓΜΗ: το ErrorLog κρατά την
 * τελευταία εμφάνιση κάθε κωδικού με έναν μετρητή (όχι πότε), το Heartbeat
 * (235) το τελευταίο run κάθε εργασίας, το BackupState (233) το τελευταίο
 * αντίγραφο. Καμία από τις τρεις δεν μπορεί να απαντήσει «πάει καλύτερα ή
 * χειρότερα από την περασμένη εβδομάδα;» — και μια πράσινη Υγεία μπορεί να
 * κρύβει cron που αστοχεί μία στις τρεις φορές.
 *
 * Ενα option με πίνακα 180 ημερών μέσα του θα χρειαζόταν
 * διάβασμα-τροποποίηση-γράψιμο σε κάθε σφάλμα και σε κάθε cron: δύο
 * ταυτόχρονα requests θα έχαναν το ένα τη μέτρηση του άλλου, σιωπηλά. Εδώ
 * κάθε αύξηση είναι ΕΝΑ `INSERT … ON DUPLICATE KEY UPDATE`, δηλαδή ατομική
 * μέσα στη MySQL — ίδιο σκεπτικό με τον ατομικό counter του ExtractionGate.
 *
 * ## Η ημερομηνία γράφεται από τη βάση
 *
 * `CURDATE()` και `DATE_SUB(CURDATE(), …)` μέσα στο ερώτημα, ποτέ `date()` από
 * PHP: η στήλη στέκεται δίπλα σε ό,τι γράφει η ίδια η βάση, και τα δύο ρολόγια
 * αυτής της εγκατάστασης έχουν ήδη διαφωνήσει κατά τρεις ώρες
 * (TimeIsReadInOnePlaceTest, CHANGELOG (84)). Μία ώρα, ένα ρολόι.
 *
 * ## Τι ΔΕΝ κρατά
 *
 * Μόνο `(ημέρα, όνομα, αριθμός)`. Κανένα user_id, καμία διαδρομή, κανένα
 * μήνυμα — τίποτα που να συνδέεται με πρόσωπο, άρα καμία νέα κατηγορία για
 * εξαγωγή ή διαγραφή GDPR. Ενας μετρητής δεν είναι αρχείο καταγραφής.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class MetricsRepository
{
    /** Το όνομα ενός μετρητή. Κλειστό σύνολο, το ορίζει ο κώδικας -- ποτέ αίτημα. */
    private const MAX_NAME = 40;

    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::METRICS);
    }

    /**
     * Ανεβάζει τον σημερινό μετρητή κατά $by.
     *
     * Τα σφάλματα σιωπούν σκόπιμα: ένας μετρητής είναι παραπροϊόν, ποτέ ο
     * λόγος που αποτυγχάνει μια αποθήκευση. Αν ο πίνακας λείπει επειδή το
     * site δεν έχει ακόμη περάσει την αναβάθμιση, το μόνο που πρέπει να
     * συμβεί είναι να μη μετρηθεί εκείνη η μέρα.
     */
    public function increment(string $metric, int $by = 1): void
    {
        global $wpdb;

        $metric = $this->clean($metric);

        if ($metric === '' || $by === 0) {
            return;
        }

        $suppressed = $wpdb->suppress_errors(true);

        // `value = value + %d` και όχι `VALUES(value)`: το δεύτερο είναι
        // deprecated από MySQL 8.0.20 και βγάζει warning σε κάθε εγγραφή.
        $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO %i (day, metric, value) VALUES (CURDATE(), %s, %d)
                 ON DUPLICATE KEY UPDATE value = value + %d',
                $this->table,
                $metric,
                $by,
                $by
            )
        );

        $wpdb->suppress_errors($suppressed);
    }

    /**
     * Οι τιμές των τελευταίων $days ημερών, νεότερες τελευταίες.
     *
     * Επιστρέφει ΜΟΝΟ τις ημέρες που έχουν γραμμή. Η οθόνη γεμίζει τα κενά με
     * μηδέν, γιατί μόνο εκείνη ξέρει αν το κενό σημαίνει «μηδέν σφάλματα»
     * (καλό) ή «δεν έτρεξε» (κακό) -- ίδιο δεδομένο, αντίθετο νόημα.
     *
     * @param  list<string> $metrics
     * @return list<array{day: string, metric: string, value: int}>
     */
    public function series(array $metrics, int $days): array
    {
        global $wpdb;

        $names = array_values(array_filter(array_map([$this, 'clean'], $metrics)));

        if ($names === [] || $days < 1) {
            return [];
        }

        $slots = implode(',', array_fill(0, count($names), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        // Το `IN (%s,%s,…)` χτίζεται από το ΠΛΗΘΟΣ των ονομάτων, όχι από
        // περιεχόμενό τους· κάθε όνομα μένει bound parameter.
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT day, metric, value FROM %i
                 WHERE metric IN ({$slots}) AND day > DATE_SUB(CURDATE(), INTERVAL %d DAY)
                 ORDER BY day ASC",
                array_merge([$this->table], $names, [$days])
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return array_map(
            static fn (array $row): array => [
                'day'    => (string) $row['day'],
                'metric' => (string) $row['metric'],
                'value'  => (int) $row['value'],
            ],
            $rows
        );
    }

    /**
     * Η σημερινή ημερομηνία όπως τη βλέπει η ΒΑΣΗ.
     *
     * Η οθόνη πρέπει να φτιάξει τη σειρά των ημερών του άξονα, και οι
     * αποθηκευμένες ημέρες γράφτηκαν με `CURDATE()`. Αν ο άξονας χτιζόταν με
     * το ρολόι της PHP, μια διαφορά ζώνης θα μετατόπιζε ολόκληρο το γράφημα
     * κατά μία μέρα -- και τα δύο ρολόγια αυτής της εγκατάστασης έχουν ήδη
     * διαφωνήσει κατά τρεις ώρες (CHANGELOG (84)). Ενα ερώτημα, ένα ρολόι.
     */
    public function today(): string
    {
        global $wpdb;

        return (string) $wpdb->get_var('SELECT CURDATE()');
    }

    /**
     * Σβήνει ό,τι είναι παλαιότερο από $days ημέρες.
     *
     * @return int Γραμμές που έφυγαν.
     */
    public function prune(int $days): int
    {
        global $wpdb;

        if ($days < 1) {
            return 0;
        }

        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE day < DATE_SUB(CURDATE(), INTERVAL %d DAY)',
                $this->table,
                $days
            )
        );

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Το όνομα ενός μετρητή δεν έρχεται ποτέ από αίτημα -- αλλά μπαίνει σε
     * στήλη με όριο, και ένα hook όνομα που μεγάλωσε θα κοβόταν σιωπηλά στη
     * μέση, φτιάχνοντας δεύτερο μετρητή που μοιάζει με τον πρώτο.
     */
    private function clean(string $metric): string
    {
        $metric = trim($metric);

        return strlen($metric) > self::MAX_NAME ? '' : $metric;
    }
}
