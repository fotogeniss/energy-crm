<?php

/**
 * Πόσο κάθεται μια σύμβαση εκεί που κάθεται — και πόσο κάθονται συνήθως οι άλλες.
 *
 * ## Γιατί ΔΕΝ είναι το `updated_at`
 *
 * Η `DashboardRepository::oldestPerStatus()` μετράει με `updated_at`, που έχει
 * `ON UPDATE CURRENT_TIMESTAMP`: απαντά «πόσο καιρό δεν την άγγιξε κανείς».
 * Χρήσιμη ερώτηση, **άλλη ερώτηση**. Εδώ ζητείται ο χρόνος **στην κατάσταση**,
 * και μια διόρθωση σε σημείωση δεν τον μηδενίζει. Πηγή είναι τα γεγονότα: το
 * `logCreation()` γράφει `to_status`, άρα **κάθε** σύμβαση έχει σημείο εισόδου,
 * ακόμη κι αν δεν μετακινήθηκε ποτέ.
 *
 * ## Η ΜΙΑ ΑΠΟΦΑΣΗ ΠΟΥ ΠΑΡΑΚΑΜΠΤΕΙ ΤΟΝ ΚΑΝΟΝΑ ΤΟΥ UserScope
 *
 * Η `typicalDays()` **δεν παίρνει `UserScope`** — και σε αυτόν τον κώδικα αυτό
 * είναι δήλωση, όχι παράλειψη. Ο λόγος:
 *
 * Ο «συνήθης χρόνος για Protergia σε εκκρεμότητα» είναι ιδιότητα του **παρόχου
 * και της ροής**, όχι των συμβάσεων κάποιου. Είναι διάμεσος πάνω σε
 * **τουλάχιστον δέκα** ανώνυμες γραμμές, χωρίς κωδικό, χωρίς πελάτη, χωρίς
 * συνεργάτη — δεν λέει τίποτα για καμία συγκεκριμένη σύμβαση και δεν
 * αντιστρέφεται. Ένας πωλητής που μαθαίνει «η Protergia συνήθως θέλει 4 μέρες»
 * μαθαίνει για την Protergia, όχι για τον διπλανό του.
 *
 * **Η εναλλακτική εξετάστηκε και απορρίφθηκε:** αν το δείγμα περιοριζόταν στο
 * δικό του δίκτυο, οι περισσότεροι συνεργάτες δεν θα είχαν ποτέ δέκα συμβάσεις
 * σε συγκεκριμένη κατάσταση **και** πάροχο, οπότε η κάρτα δεν θα εμφανιζόταν
 * σχεδόν ποτέ — δηλαδή ο κανόνας θα τηρούνταν και το χαρακτηριστικό δεν θα
 * υπήρχε. Αν αυτή η ανταλλαγή κριθεί λάθος, αλλάζει **εδώ**: μπαίνει `UserScope`
 * και `ScopeClause::forScope()` στο `WHERE`, και το `MIN_SAMPLE` αποφασίζει
 * μόνο του πόσο συχνά θα σιωπά η κάρτα.
 *
 * ## Διάμεσος, όχι μέσος όρος
 *
 * Οι χρόνοι παραμονής είναι ασύμμετροι: μία σύμβαση ξεχασμένη 200 μέρες
 * μετακινεί τον μέσο όρο τόσο ώστε **τίποτα να μη φαίνεται ποτέ αργό**. Ο
 * διάμεσος δεν το παθαίνει. Υπολογίζεται σε PHP επειδή η MySQL δεν έχει
 * `MEDIAN()` — και επειδή το αποτέλεσμα είναι ούτως ή άλλως προσωρινά
 * αποθηκευμένο, το κόστος πληρώνεται δυο φορές τη μέρα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class StatusDwellRepository
{
    /**
     * Κάτω από τόσες μετρήσεις, καμία σύγκριση.
     *
     * **Δέκα και όχι πέντε**, που είναι το `MIN_SAMPLE` της καρτέλας συνεργάτη.
     * Εκεί ο αριθμός περιγράφει τη **δική σου** δουλειά και ο ίδιος ο χρήστης
     * ξέρει το μέγεθος του δείγματος. Εδώ ο αριθμός είναι ισχυρισμός για το τι
     * είναι «φυσιολογικό», και ένα ψεύτικο «αυτή αργεί» εκπαιδεύει τον
     * συνεργάτη να αγνοεί την κάρτα — που τη σκοτώνει και τις φορές που έχει
     * δίκιο. Ο πιο ακριβός τρόπος να χαλάσει αυτό το χαρακτηριστικό είναι να
     * μιλάει όταν δεν ξέρει.
     */
    public const MIN_SAMPLE = 10;

    /** Πόσο ώρα ζει η μέτρηση του συνόλου. Αλλάζει αργά· δεν χρειάζεται φρέσκια. */
    private const CACHE_SECONDS = 12 * HOUR_IN_SECONDS;

    /**
     * Το `0` σημαίνει «χωρίς προσωρινή αποθήκευση», και υπάρχει για τα tests.
     *
     * Χωρίς αυτό, ο δεύτερος έλεγχος θα διάβαζε το αποτέλεσμα του πρώτου και θα
     * περνούσε χωρίς να τρέξει το ερώτημα — πράσινο που δεν μέτρησε τίποτα, η
     * οικογένεια σφαλμάτων που κυνηγάει όλο το §3. Η προσωρινή αποθήκευση είναι
     * υποδομή του WordPress· αυτό που αξίζει να ελεγχθεί είναι το SQL και ο
     * διάμεσος.
     */
    public function __construct(private readonly int $cacheSeconds = self::CACHE_SECONDS)
    {
    }

    /**
     * Πόσες μέρες βρίσκεται ΑΥΤΗ η σύμβαση στην τρέχουσα κατάστασή της.
     *
     * `null` όταν δεν υπάρχει καθόλου γεγονός εισόδου — που δεν πρέπει να
     * συμβαίνει, αλλά μια σύμβαση φτιαγμένη με raw SQL (εισαγωγή, δοκιμή,
     * μετάπτωση) δεν έχει ιστορικό, και ένα `0` εκεί θα έλεγε «μπήκε σήμερα».
     */
    public function daysInStatus(int $contractId, string $status): ?int
    {
        global $wpdb;

        if ($contractId <= 0 || $status === '') {
            return null;
        }

        // Ώρα βάσης προς ώρα βάσης: το created_at το γράφει η MySQL. Η σύγκριση
        // με current_time() θα ανακάτευε ζώνες — δες EventRepository.
        $days = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT DATEDIFF(NOW(), MAX(created_at)) FROM %i
                 WHERE contract_id = %d AND to_status = %s',
                Tables::name(Tables::EVENTS),
                $contractId,
                $status
            )
        );

        return null === $days ? null : (int) $days;
    }

    /**
     * Ο συνήθης χρόνος παραμονής σε μια κατάσταση, σε μέρες.
     *
     * Μετράει **μόνο συμβάσεις που ΕΦΥΓΑΝ** από την κατάσταση. Οι κολλημένες
     * εξαιρούνται επίτηδες: αν μετρούσαν, το «συνηθισμένο» θα μεγάλωνε όσο
     * μεγαλώνει η ουρά, και η κάρτα θα σταματούσε να χτυπάει ακριβώς όταν τα
     * πράγματα χειροτερεύουν.
     *
     * @return array{days: int, sample: int}|null null όταν το δείγμα δεν φτάνει
     */
    public function typicalDays(string $status, ?int $providerId): ?array
    {
        if ($status === '') {
            return null;
        }

        $key = 'ecrm_dwell_' . md5($status . '|' . (string) $providerId);

        if ($this->cacheSeconds > 0) {
            $cached = get_transient($key);

            if (is_array($cached)) {
                return $cached['sample'] >= self::MIN_SAMPLE ? $cached : null;
            }
        }

        $result = $this->measure($status, $providerId);

        if ($this->cacheSeconds > 0) {
            set_transient($key, $result, $this->cacheSeconds);
        }

        return $result['sample'] >= self::MIN_SAMPLE ? $result : null;
    }

    /**
     * @return array{days: int, sample: int}
     */
    private function measure(string $status, ?int $providerId): array
    {
        global $wpdb;

        $events    = Tables::name(Tables::EVENTS);
        $contracts = Tables::name(Tables::CONTRACTS);

        // Η εσωτερική επιλογή δίνει, ανά σύμβαση, την ΤΕΛΕΥΤΑΙΑ είσοδο στην
        // κατάσταση: μια σύμβαση που πήγε πίσω και ξαναήρθε μετράει από την
        // τελευταία φορά, όπως ακριβώς τη βλέπει ο συνεργάτης σήμερα.
        // Η συσχετισμένη υποεπιλογή δίνει την πρώτη ΕΞΟΔΟ μετά από εκείνη.
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DATEDIFF(
                          ( SELECT MIN(x.created_at) FROM %i x
                             WHERE x.contract_id = ent.contract_id
                               AND x.created_at > ent.entered
                               AND x.to_status IS NOT NULL
                               AND x.to_status <> %s ),
                          ent.entered
                        ) AS dwell
                 FROM ( SELECT contract_id, MAX(created_at) AS entered
                          FROM %i WHERE to_status = %s GROUP BY contract_id ) ent
                 JOIN %i c ON c.id = ent.contract_id
                 WHERE ( %d = 0 OR c.provider_id = %d )
                 HAVING dwell IS NOT NULL AND dwell >= 0',
                $events,
                $status,
                $events,
                $status,
                $contracts,
                (int) $providerId,
                (int) $providerId
            )
        );

        $days = array_map('intval', $rows ?: []);

        return ['days' => self::median($days), 'sample' => count($days)];
    }

    /**
     * @param list<int> $values
     */
    private static function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);

        $middle = intdiv(count($values), 2);

        // Άρτιο πλήθος: ο μέσος όρος των δύο μεσαίων, στρογγυλεμένος. Σε μέρες
        // δεν υπάρχει μισή μέρα που να λέει κάτι στον συνεργάτη.
        return count($values) % 2 === 1
            ? $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }
}
