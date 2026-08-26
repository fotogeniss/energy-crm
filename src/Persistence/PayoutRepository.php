<?php

/**
 * Οι παρτίδες εκκαθάρισης — το ελάχιστο που χρειάστηκε να βγει από το wp-admin.
 *
 * ## Γιατί υπάρχει, και γιατί είναι τόσο μικρό
 *
 * Το `ECRM_Payouts::pay()` είναι χειριστής `admin_post` που τελειώνει σε `exit`.
 * Το §6γ (1) το γράφει από τις 18/08: **η σουίτα δεν μπορεί να τον καλέσει**,
 * και έτσι η πράξη «σημείωσε αυτή την παρτίδα ως πληρωμένη» ήταν ο μόνος
 * χειρισμός χρημάτων του προϊόντος χωρίς κανέναν έλεγχο.
 *
 * Δεν μεταφέρθηκε ολόκληρη η οθόνη — αυτό είναι το μισό του C4 που περιμένει
 * απόφαση (ποιος επιτρέπεται να πληρώνει). Βγήκε **μόνο το ερώτημα**, ώστε να
 * μπορεί να ελεγχθεί ο κανόνας που δεν ελεγχόταν ποτέ.
 *
 * `find()`/`statementLines()`/`forScope()` (build queue 11) ήρθαν αργότερα, για
 * τον ίδιο λόγο: η βεβαίωση PDF ενός συνεργάτη (νέα REST διαδρομή, scoped) και
 * το wp-admin PDF (ECRM_Payouts::pdf(), admins) έπρεπε να διαβάζουν ΤΗΝ ΙΔΙΑ
 * γραμμή κώδικα, όχι δύο αντίγραφα του ίδιου join που θα ξεσυγχρόνιζαν αργά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Commission\CommissionAmount;

final class PayoutRepository
{
    /**
     * Σημειώνει μια εκκρεμή παρτίδα ως πληρωμένη. `false` αν δεν ήταν εκκρεμής.
     *
     * ## Η ΩΡΑ ΤΗ ΓΡΑΦΕΙ Η ΒΑΣΗ, ΟΧΙ Η PHP — ΚΑΙ ΑΥΤΟ ΤΟ ΔΙΔΑΞΕ ΑΠΟΤΥΧΙΑ
     *
     * Το `created_at` της ίδιας γραμμής το βάζει η MySQL
     * (`DEFAULT CURRENT_TIMESTAMP`), δηλαδή είναι στη **ζώνη της βάσης**. Το
     * `paid_at` γραφόταν από PHP, και ό,τι ζώνη κι αν διαλέξει η PHP είναι
     * **υπόθεση** για το τι κάνει η βάση δίπλα της.
     *
     * Γραφόταν `current_time('mysql', true)` — UTC. Το άλλαξα σε ώρα site
     * θεωρώντας ότι το `created_at` είναι ώρα διακομιστή, **χωρίς να το
     * μετρήσω**. Ο έλεγχος `testThePaidTimeIsInTheSameZoneAsTheCreatedTimeBesideIt`
     * κοκκίνισε με **ακριβώς 10800 δευτερόλεπτα** — τρεις ώρες, η θερινή
     * διαφορά Ελλάδας. Δηλαδή σε αυτό το περιβάλλον η MySQL τρέχει σε **UTC**,
     * η παλιά γραφή ήταν συνεπής, και η «διόρθωσή» μου έφερε τη διαφορά.
     *
     * Η απάντηση δεν είναι να μαντέψω σωστά τη δεύτερη φορά. **Είναι να μη
     * μαντέψω:** το `NOW()` το βάζει η ίδια η βάση, με το ίδιο ρολόι που βάζει
     * και το `created_at`. Οι δύο στήλες δεν μπορούν να αποκλίνουν σε κανέναν
     * διακομιστή, όποια ζώνη κι αν έχει η MySQL και όποια το WordPress.
     *
     * *Ο κανόνας που έλειπε από την οικογένεια των (72), (77), (80): εκεί η
     * παγίδα ήταν «μη συγκρίνεις ώρα site με UTC». Εδώ είναι ένα βήμα πιο πίσω
     * — **μη γράφεις με ρολόι διαφορετικό από εκείνο που γράφει τη διπλανή
     * στήλη.***
     *
     * ## Η συνθήκη `status = 'pending'` δεν είναι διακοσμητική
     *
     * Είναι που κάνει το διπλό κλικ ακίνδυνο: το δεύτερο `UPDATE` δεν βρίσκει
     * γραμμή, άρα δεν ξαναγράφει το `paid_at` με νεότερη ώρα.
     */
    /**
     * Ακυρώνει μια ΕΚΚΡΕΜΗ παρτίδα -- ατομικά, χωρίς το παράθυρο ανάμεσα σε
     * ανάγνωση και γραφή που είχε ο παλιός `ECRM_Payouts::remove()` (εύρημα
     * ελέγχου ασφαλείας/λογικής #3, 26/08/2026).
     *
     * ## Η σειρά είναι το προϊόν, όχι λεπτομέρεια
     *
     * Πρώτα σβήνεται η ΙΔΙΑ η γραμμή της παρτίδας -- με τον όρο `status =
     * 'pending'` μέσα στην ίδια, ατομική πρόταση DELETE, ίδιο σχήμα με το
     * `markPaid()` παραπάνω. Μόνο αν αυτό πέτυχε αποσυνδέονται οι συμβάσεις.
     *
     * Ο παλιός κώδικας έκανε το αντίστροφο: διάβαζε το status με ξεχωριστό
     * SELECT, μετά αποσύνδεε τις συμβάσεις ΧΩΡΙΣ όρο, και μόνο στο τέλος
     * δοκίμαζε το guarded DELETE. Ένα ταυτόχρονο `markPaid()` ανάμεσα στο
     * SELECT και το UPDATE των συμβάσεων άφηνε πίσω μια παρτίδα «paid» χωρίς
     * καμία σύμβαση επάνω της -- και οι ίδιες συμβάσεις, πλέον χωρίς
     * `payout_id`, ξαναγύριζαν στις ανεξόφλητες και μπορούσαν να πληρωθούν
     * ΞΑΝΑ σε νέα παρτίδα.
     *
     * Με τη διαγραφή πρώτη, το ερώτημα «πρόλαβε να πληρωθεί;» έχει ΜΙΑ
     * απάντηση, από ΜΙΑ ατομική πρόταση: αν η DELETE δεν βρει πλέον γραμμή σε
     * εκκρεμότητα, καμία σύμβαση δεν αγγίζεται, και μια παρτίδα που μόλις
     * πληρώθηκε δεν μπορεί πια να χάσει τις συμβάσεις της -- η ίδια της η
     * γραμμή έχει πάψει να υπάρχει τη στιγμή που κάτι άλλο τη σημειώνει
     * πληρωμένη.
     *
     * `false` σημαίνει «δεν διαγράφηκε»: είτε δεν υπήρχε ποτέ, είτε είναι ήδη
     * πληρωμένη. Ο καλών ξεχωρίζει τις δύο περιπτώσεις με `find()`.
     */
    public function deletePending(int $payoutId): bool
    {
        global $wpdb;

        if ($payoutId <= 0) {
            return false;
        }

        // Πιάνουμε ΠΟΙΕΣ συμβάσεις δείχνουν σε αυτή την παρτίδα πριν τη
        // διαγραφή -- όχι ως σημείο απόφασης (αυτό παραμένει αποκλειστικά το
        // guarded DELETE παρακάτω), αλλά επειδή το FK contracts.payout_id →
        // payouts.id έχει ήδη ON DELETE SET NULL (βλ. AddForeignKeys::relations()):
        // μόλις η γραμμή της παρτίδας διαγραφεί, η ίδια η βάση μηδενίζει το
        // payout_id αυτόματα, οπότε ένα δικό μας μετέπειτα `WHERE payout_id =
        // %d` δεν θα έβρισκε πια καμία γραμμή. Το FK δεν αγγίζει το
        // payout_amount -- αυτό μένει δική μας δουλειά.
        $contractIds = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE payout_id = %d',
                Tables::name(Tables::CONTRACTS),
                $payoutId
            )
        );

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE id = %d AND status = 'pending'",
                Tables::name(Tables::PAYOUTS),
                $payoutId
            )
        );

        if (! $deleted) {
            return false;
        }

        // Η παρτίδα όντως διαγράφηκε ενώ ήταν ακόμα pending -- το FK μόλις
        // μηδένισε το payout_id των παραπάνω συμβάσεων· απομένει μόνο να
        // καθαρίσουμε το στιγμιότυπο ποσού τους. Αν μια από αυτές έχει ήδη
        // αποσυνδεθεί εν τω μεταξύ (π.χ. ταυτόχρονη ακύρωση), το WHERE απλά
        // δεν βρίσκει τίποτα -- αβλαβές.
        if ($contractIds) {
            $placeholders = implode(',', array_fill(0, count($contractIds), '%d'));

            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
            // Table name bound with %i, every value a bound parameter -- what
            // phpcs cannot verify is the `IN (%d,%d,…)` fragment itself, whose
            // length varies with how many contracts were in the batch. That
            // fragment is built two lines up from nothing but the literal
            // string "%d" -- no request data reaches it. Same exemption already
            // used by ContractRepository::reachableAmong() for the same reason.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE %i SET payout_amount = NULL WHERE id IN ({$placeholders})",
                    [Tables::name(Tables::CONTRACTS), ...$contractIds]
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        }

        return true;
    }

    public function markPaid(int $payoutId): bool
    {
        global $wpdb;

        if ($payoutId <= 0) {
            return false;
        }

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET status = 'paid', paid_at = NOW() WHERE id = %d AND status = 'pending'",
                Tables::name(Tables::PAYOUTS),
                $payoutId
            )
        );

        return (int) $affected > 0;
    }

    /**
     * Μία παρτίδα εκκαθάρισης, ή `null`. Δεν ελέγχει scope — αυτό είναι δουλειά
     * του καλούντος, ΠΡΙΝ αποφασίσει τι θα δείξει (δες `UserScope::includes()`).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $payoutId): ?array
    {
        global $wpdb;

        if ($payoutId <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d',
                Tables::name(Tables::PAYOUTS),
                $payoutId
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Οι παρτίδες που επιτρέπεται να δει ένα scope — δικές του, ή ολόκληρη η
     * εγκατάσταση για διαχειριστή.
     *
     * @return list<array<string, mixed>>
     */
    public function forScope(UserScope $scope, int $limit = 200): array
    {
        global $wpdb;

        [$clause, $scopeParams] = ScopeClause::forScope($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE 1 = 1{$clause} ORDER BY id DESC LIMIT " . max(1, $limit),
                [Tables::name(Tables::PAYOUTS), ...$scopeParams]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * Οι γραμμές μιας βεβαίωσης εκκαθάρισης — το στιγμιότυπο ποσού ανά σύμβαση
     * με το οποίο σφραγίστηκε η παρτίδα, όχι ο σημερινός υπολογισμός (δες
     * `CommissionAmount::of()` — η ίδια απόφαση, ένα από τα σημεία της).
     *
     * Κοινή θέση για wp-admin (`ECRM_Payouts::pdf()`) και τη REST βεβαίωση του
     * συνεργάτη· πριν το build queue 11 αυτό το join ζούσε μόνο μέσα στο
     * wp-admin handler.
     *
     * @return list<array{code:string,customer:string,provider:string,amount:float}>
     */
    public function statementLines(int $payoutId): array
    {
        global $wpdb;

        if ($payoutId <= 0) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.code, c.provider_id, c.program_id, c.energy_type, c.category, c.status,
                        c.payout_amount,
                        p.name AS provider_name, cu.first_name, cu.last_name, cu.company_name
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 WHERE c.payout_id = %d ORDER BY c.code',
                Tables::name(Tables::CONTRACTS),
                Tables::name(Tables::CUSTOMERS),
                Tables::name(Tables::PROVIDERS),
                $payoutId
            ),
            ARRAY_A
        );

        $lines = [];

        foreach ((array) $rows as $r) {
            $name = $r['company_name'] ?: trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));

            $lines[] = [
                'code'     => $r['code'] ?: '—',
                'customer' => $name !== '' ? $name : '—',
                'provider' => $r['provider_name'] ?: '—',
                'amount'   => CommissionAmount::of(
                    $r,
                    static fn (array $row): float =>
                        class_exists('ECRM_Commissions') ? \ECRM_Commissions::amount_for($row) : 0.0
                ),
            ];
        }

        return $lines;
    }

    /**
     * Αν αυτή η σύμβαση ανήκει σε παρτίδα που έχει ΗΔΗ πληρωθεί.
     *
     * Το `CancellationGate` το ρωτάει πριν αφήσει μια σύμβαση να ακυρωθεί: μια
     * σύμβαση που μπήκε σε πληρωμένη παρτίδα έχει ήδη δώσει χρήμα σε
     * συνεργάτη -- η ακύρωσή της τώρα θα άφηνε αυτό το χρήμα χωρίς αντίκρισμα,
     * σιωπηλά, χωρίς κανένα ίχνος ότι κάτι χρειάζεται διευθέτηση. Ο ιδιοκτήτης
     * το επιβεβαίωσε ρητά (26/08, εύρημα ελέγχου ασφαλείας/λογικής #2): ίδια
     * αντιμετώπιση με το «η σύμβαση υπήρξε Ενεργή».
     *
     * Το `isPayable()` περιλαμβάνει `Routed`/`Resolved`, όχι μόνο `Active` --
     * γι' αυτό αυτός ο έλεγχος δεν κοιτάζει καθόλου κατάσταση, μόνο αν η
     * σύμβαση κουβαλά ήδη `payout_id` προς παρτίδα `paid`.
     */
    public function isPartOfPaidBatch(int $contractId): bool
    {
        global $wpdb;

        if ($contractId <= 0) {
            return false;
        }

        $status = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT p.status FROM %i c INNER JOIN %i p ON p.id = c.payout_id WHERE c.id = %d',
                Tables::name(Tables::CONTRACTS),
                Tables::name(Tables::PAYOUTS),
                $contractId
            )
        );

        return $status === 'paid';
    }

    /**
     * Βγάζει τη σύμβαση από την ΕΚΚΡΕΜΗ παρτίδα της, αν έχει μία.
     *
     * Καλείται από το `ContractLifecycle` αμέσως μετά από κάθε επιτυχή
     * ακύρωση: το σύνολο μιας παρτίδας που δεν έχει πληρωθεί ακόμα δεν πρέπει
     * να συνεχίζει να περιλαμβάνει μια σύμβαση που μόλις ακυρώθηκε. Ο
     * ιδιοκτήτης το επιβεβαίωσε ρητά (26/08). Παρτίδα ήδη πληρωμένη δεν
     * αγγίζεται εδώ καθόλου -- εκείνη τη διαδρομή την έχει ήδη κόψει το
     * `CancellationGate` πριν φτάσουμε ποτέ ως εδώ.
     */
    public function releaseFromPendingBatch(int $contractId): void
    {
        global $wpdb;

        if ($contractId <= 0) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i c INNER JOIN %i p ON p.id = c.payout_id
                 SET c.payout_id = NULL, c.payout_amount = NULL
                 WHERE c.id = %d AND p.status = 'pending'",
                Tables::name(Tables::CONTRACTS),
                Tables::name(Tables::PAYOUTS),
                $contractId
            )
        );
    }
}
