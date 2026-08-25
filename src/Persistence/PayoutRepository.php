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
}
