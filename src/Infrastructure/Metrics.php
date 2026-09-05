<?php

/**
 * Τα ονόματα των μετρητών, σε ένα σημείο.
 *
 * Ο πίνακας κρατά `(ημέρα, όνομα, αριθμός)` και δεν ξέρει τι σημαίνει το
 * όνομα. Αν το «ποιος γράφει» και το «ποιος διαβάζει» έλεγαν ο καθένας το
 * δικό του string, μια ορθογραφική διαφορά θα έφτιαχνε δεύτερο μετρητή που
 * γεμίζει σιωπηλά ενώ η οθόνη δείχνει μηδέν -- ακριβώς το είδος «όλα πράσινα
 * ενώ τίποτα δεν δουλεύει» που περιγράφει το AUDIT-OPERATIONS #3.
 *
 * Λεπτό στρώμα πάνω από το MetricsRepository και τίποτε άλλο: η βάση
 * αγγίζεται μόνο από το Persistence (§1.12), οπότε αυτή η κλάση δεν αγγίζει
 * καθόλου τη βάση απευθείας.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Persistence\MetricsRepository;

final class Metrics
{
    /** Κάθε καταγραφή του ErrorLog -- και οι επαναλήψεις γνωστού κωδικού. */
    public const ERRORS = 'errors';

    /** Ενα ολοκληρωμένο αντίγραφο ασφαλείας μέσω tools/backup.php. */
    public const BACKUP = 'backup';

    /** Αποτυχημένη σύνδεση που μετρήθηκε -- δηλαδή δεν ήταν ήδη κλειδωμένη. */
    public const LOGIN_FAILED = 'login_failed';

    /** Προσπάθεια σύνδεσης που κόπηκε πριν καν ελεγχθεί ο κωδικός. */
    public const LOGIN_BLOCKED = 'login_blocked';

    /** Ο ημερήσιος έλεγχος βρήκε τον φάκελο εγγράφων δημόσια προσβάσιμο. */
    public const DOCS_EXPOSED = 'docs_exposed';

    /** Ετικέτα εγγράφου που διορθώθηκε από αυτόματη αναγνώριση. */
    public const DOC_KIND_FIXED = 'doc_kind_fixed';

    /** Πόσες ημέρες πίσω κρατιέται το ιστορικό, πριν το φίλτρο. */
    public const KEEP_DAYS = 180;

    /** Μία εκτέλεση προγραμματισμένης εργασίας. Το hook μπαίνει στο όνομα. */
    public static function cron(string $hook): string
    {
        return 'cron:' . $hook;
    }

    public static function bump(string $metric, int $by = 1): void
    {
        (new MetricsRepository())->increment($metric, $by);
    }

    /**
     * Πόσες ημέρες κρατιούνται. Φιλτράρεται, όπως η διατήρηση εξαγωγών.
     */
    public static function keepDays(): int
    {
        /** @var mixed $days */
        $days = apply_filters('ecrm_metrics_retention_days', self::KEEP_DAYS);

        return max(1, (int) $days);
    }
}
