<?php

/**
 * Ποιο αίτημα δημιουργίας έχει ήδη εκτελεστεί, και τι έβγαλε.
 *
 * ## Γιατί δεσμεύεται ΠΡΙΝ γραφτεί οτιδήποτε
 *
 * Το προφανές σχέδιο -- στήλη `client_request_id` πάνω στη σύμβαση με UNIQUE
 * ευρετήριο -- πιάνει τη δεύτερη σύμβαση και αφήνει τον δεύτερο **πελάτη**.
 * Η σειρά στο `ContractSaveController::save()` είναι πελάτης πρώτα, σύμβαση
 * μετά· ώσπου να σκάσει το UNIQUE της σύμβασης, η διπλή γραμμή πελάτη έχει
 * ήδη γραφτεί. Και ο διπλός πελάτης είναι ακριβώς αυτό που ο τυφλός δείκτης
 * `afm_hash` και ο `DraftExitGate` υπάρχουν για να αποτρέψουν.
 *
 * Γι' αυτό η δέσμευση είναι δική της γραμμή, σε δικό της πίνακα, και γίνεται
 * πριν διαβαστεί καν το πρώτο πεδίο του πελάτη.
 *
 * ## Η διαιτησία γίνεται στη βάση, όχι σε PHP
 *
 * Δύο αιτήματα με το ίδιο κλειδί μπορούν να τρέχουν στο ίδιο χιλιοστό --
 * ρεαλιστικό με 20-40 ταυτόχρονες αιτήσεις. Ενα `SELECT` και μετά `INSERT`
 * θα περνούσε και τα δύο. Το `INSERT IGNORE` πάνω σε UNIQUE
 * `(partner_user_id, request_key)` αφήνει τη MySQL να πει ποιο ήταν πρώτο:
 * επιστρέφει 1 σε ακριβώς έναν από τους δύο. Ιδιο σκεπτικό με τα υπάρχοντα
 * race tests του project (`LeadConversionClaimRaceTest`,
 * `PayoutDeletePendingRaceTest`).
 *
 * ## Το κλειδί ανήκει στον συνεργάτη
 *
 * Το UNIQUE είναι σύνθετο -- χρήστης ΚΑΙ κλειδί -- ώστε ένα uuid που στέλνει
 * ο ένας να μη μπορεί ποτέ να δεσμεύσει ή να διαβάσει τη θέση του άλλου.
 * Χωρίς αυτό, ένα κλειδί θα ήταν καθολικός πόρος που ο καθένας μπορεί να
 * πιάσει πρώτος.
 *
 * ## Ολα τα timestamps τα γράφει η βάση
 *
 * `DEFAULT CURRENT_TIMESTAMP` και `NOW()` μέσα στο ερώτημα, ποτέ ώρα από
 * PHP: η στήλη στέκεται δίπλα σε στήλες που γράφει η βάση, και δύο ρολόγια
 * στον ίδιο πίνακα είναι το λάθος που κόστισε τρεις διορθώσεις τον Αύγουστο
 * (βλ. `TimeIsReadInOnePlaceTest`).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class RequestKeyRepository
{
    /**
     * Πόσο κρατά μια δέσμευση που δεν ολοκληρώθηκε ποτέ.
     *
     * Μια αίτηση που έσκασε σε fatal error αφήνει πίσω γραμμή με κενό
     * `contract_id`. Χωρίς όριο, ο συνεργάτης που ξαναπατά θα έπαιρνε «η
     * αίτηση εκτελείται ήδη» για πάντα. Εξήντα δευτερόλεπτα είναι άνετα πάνω
     * από τον χρόνο μιας αποθήκευσης και αρκετά λίγο ώστε να ξεκολλήσει μόνο
     * του πριν προλάβει ο χρήστης να το αναφέρει.
     */
    public const STALE_SECONDS = 60;

    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::REQUEST_KEYS);
    }

    /**
     * Δέσμευσε το κλειδί για αυτόν τον χρήστη.
     *
     * @return bool True όταν η δέσμευση είναι δική μας και επιτρέπεται να
     *              προχωρήσει η δημιουργία. False όταν κάποιος άλλος την
     *              κρατά -- είτε γιατί τρέχει τώρα, είτε γιατί τελείωσε ήδη
     *              και το αποτέλεσμα διαβάζεται με `contractFor()`.
     */
    public function claim(int $userId, string $key): bool
    {
        global $wpdb;

        if ($userId <= 0 || $key === '') {
            return false;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO %i (partner_user_id, request_key) VALUES (%d, %s)',
                [$this->table, $userId, $key]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        if ($inserted === 1) {
            return true;
        }

        return $this->reclaimStale($userId, $key);
    }

    /**
     * Το αποτέλεσμα μιας δέσμευσης που ολοκληρώθηκε, ή null όσο τρέχει ακόμα.
     *
     * Ρωτιέται μόνο αφού το `claim()` έχει πει όχι: null εδώ σημαίνει
     * «κάποιος το εκτελεί αυτή τη στιγμή», όχι «δεν υπάρχει».
     */
    public function contractFor(int $userId, string $key): ?int
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $contractId = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT contract_id FROM %i WHERE partner_user_id = %d AND request_key = %s',
                [$this->table, $userId, $key]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $contractId !== null && (int) $contractId > 0 ? (int) $contractId : null;
    }

    /**
     * Η δέσμευση πέτυχε: αυτή είναι η σύμβαση που γεννήθηκε.
     *
     * Μόνο πάνω σε δική μας, ακόμα ανοιχτή δέσμευση (`contract_id IS NULL`):
     * μια γραμμή που έχει ήδη αποτέλεσμα δεν ξαναγράφεται ποτέ, ώστε ένα
     * καθυστερημένο δεύτερο αίτημα να μην μπορεί να αλλάξει το τι θα δει το
     * replay.
     */
    public function complete(int $userId, string $key, int $contractId): void
    {
        global $wpdb;

        if ($contractId <= 0) {
            return;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET contract_id = %d, completed_at = NOW()
                 WHERE partner_user_id = %d AND request_key = %s AND contract_id IS NULL',
                [$this->table, $contractId, $userId, $key]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    }

    /**
     * Η δέσμευση δεν οδήγησε σε σύμβαση -- άφησέ τη ελεύθερη.
     *
     * Καλείται σε **κάθε** άρνηση (λάθος ΑΦΜ, λάθος email, 403, 404, 409).
     * Χωρίς αυτό, ο συνεργάτης που διορθώνει το ΑΦΜ και ξαναπατά με το ίδιο
     * κλειδί -- που είναι ακριβώς αυτό που κάνει η φόρμα, αφού η πρόθεση
     * είναι η ίδια -- θα κλειδωνόταν έξω από τη δική του δημιουργία.
     */
    public function release(int $userId, string $key): void
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE partner_user_id = %d AND request_key = %s AND contract_id IS NULL',
                [$this->table, $userId, $key]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    }

    /**
     * Σβήσε ολοκληρωμένες δεσμεύσεις παλαιότερες από τόσες ημέρες.
     *
     * Κλαδεύεται από την ημερήσια `Retention::onScheduledSweep()` -- όχι δική
     * της cron: είναι ένα DELETE λίγων γραμμών, και μια πέμπτη προγραμματισμένη
     * εργασία θα ήταν ένα ακόμη πράγμα που μπορεί να μην τρέξει. Ιδιο σκεπτικό
     * με το `MetricsRepository::prune()` που ήδη κάθεται εκεί.
     *
     * @return int Πόσες γραμμές έφυγαν.
     */
    public function purge(int $olderThanDays): int
    {
        global $wpdb;

        if ($olderThanDays <= 0) {
            return 0;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE created_at < (NOW() - INTERVAL %d DAY)',
                [$this->table, $olderThanDays]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * Ξαναπάρε μια δέσμευση που έμεινε ανοιχτή πάνω από `STALE_SECONDS`.
     *
     * Το `created_at = NOW()` δεν είναι διακόσμηση: ξεκινά το χρονόμετρο από
     * την αρχή, ώστε δύο αιτήσεις που φτάνουν ταυτόχρονα σε μια ξεχασμένη
     * γραμμή να μην την ανακτήσουν και οι δύο. Το `affected_rows === 1` το
     * αποφασίζει η βάση, όπως και στο `claim()`.
     */
    private function reclaimStale(int $userId, string $key): bool
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $reclaimed = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET created_at = NOW()
                 WHERE partner_user_id = %d AND request_key = %s AND contract_id IS NULL
                   AND created_at < (NOW() - INTERVAL %d SECOND)',
                [$this->table, $userId, $key, self::STALE_SECONDS]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $reclaimed === 1;
    }
}
