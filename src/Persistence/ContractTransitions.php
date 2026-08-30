<?php

/**
 * The rows behind a status change, and the sweep that finds what is due.
 *
 * Three methods, none of which takes a UserScope, and that is the property they
 * were grouped by. The policy that admits them — and the test any future
 * addition has to pass before it is let in — is in ARCHITECTURE.md under
 * «Αναγνώσεις χωρίς actor».
 *
 * In short, for these three: the transition is reached through
 * Domain\Contract\ContractLifecycle, whose callers have already resolved the
 * contract through a scoped read, and adding a second check here would not make
 * it safer — it would make the caller believe the check lives here. The sweep
 * runs from cron, on behalf of nobody, which is the whole point of it existing.
 *
 * Having them in a class named for that property is the guard. The argument used
 * to be a comment in the middle of a 930-line file, and a comment protects a
 * rule only for as long as people read it; a class answers "does this belong
 * here?" by which file you had to open.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class ContractTransitions
{
    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::CONTRACTS);
    }

    /** The status a contract is in right now; '' when there is no such row. */
    public function statusOf(int $contractId): string
    {
        global $wpdb;

        $status = $wpdb->get_var(
            $wpdb->prepare('SELECT status FROM %i WHERE id = %d', $this->table, $contractId)
        );

        return $status === null ? '' : (string) $status;
    }

    /**
     * Write the new status, and whatever columns come with it.
     *
     * AUDIT 30/08: αυτή η εγγραφή ήταν σκέτο `$wpdb->update(..., ['id' =>
     * $contractId])` -- χωρίς όρο πάνω στην κατάσταση από την οποία ξεκινάει.
     * `ContractLifecycle::moveTo()` διαβάζει το `$from` μία φορά, νωρίτερα,
     * με ξεχωριστό ερώτημα (`statusOf()`) -- ανάμεσα σε αυτό το διάβασμα και
     * αυτή τη γραφή δεν υπήρχε καμία εγγύηση ότι η σύμβαση έμεινε εκεί. Δύο
     * ταυτόχρονες κλήσεις moveTo() πάνω στην ίδια σύμβαση (π.χ. το cron sweep
     * προς 'active' και μια μαζική ενέργεια προς 'cancelled' -- ή ένα διπλό
     * κλικ) θα έγραφαν και οι δύο, η δεύτερη σιωπηλά πάνω από την πρώτη, χωρίς
     * κανένα σφάλμα και χωρίς loser.
     *
     * Η λύση είναι το ίδιο σχήμα που ήδη χρησιμοποιεί το PayoutRepository
     * (deletePending()/markPaid()) και το UnprotectedDocuments::flagProtected():
     * μια δεσμευμένη (guarded) UPDATE με τον όρο `WHERE status = $expectedFrom`
     * μέσα στο ίδιο ερώτημα.
     *
     * Η νίκη κρίνεται με ΔΥΟ διαφορετικούς τρόπους ανάλογα με το αν η τιμή
     * αλλάζει, γιατί ένας μόνο τρόπος αποδείχτηκε λάθος και στις δύο άκρες:
     *
     * - `$status !== $expectedFrom` (κανονική μετάβαση): οι επηρεασμένες
     *   γραμμές του `$wpdb->update()` είναι αξιόπιστο σήμα -- η τιμή αλλάζει
     *   πάντα όταν ο όρος ταιριάξει, άρα >0 σημαίνει σίγουρη νίκη. Πρώτη
     *   μορφή αυτής της μεθόδου δοκίμασε αντ' αυτού «ξαναδιάβασε το status
     *   μετά τη γραφή και σύγκρινε με το target» -- έσπασε στο εξής σενάριο,
     *   πιασμένο από `ContractLifecycleMoveToRaceTest`: δύο κλήσεις θέλουν
     *   τον ΙΔΙΟ στόχο, η μία κερδίζει, η δεύτερη χάνει το guard (0 γραμμές)
     *   αλλά το ξαναδιάβασμα βλέπει τη σωστή τελική κατάσταση ΕΤΣΙ Κ' ΕΤΣΙ --
     *   και θα ανέφερε ψευδώς νίκη στη χαμένη, διπλασιάζοντας το event log
     *   και τις ειδοποιήσεις στη `moveTo()`.
     * - `$status === $expectedFrom` (`force => true` χωρίς πραγματική αλλαγή):
     *   εδώ δεν υπάρχει ανταγωνισμός ανάμεσα σε δύο ΔΙΑΦΟΡΕΤΙΚΕΣ τιμές, οπότε
     *   το ξαναδιάβασμα είναι ασφαλές -- ρωτάει απλώς αν η γραμμή είναι ΤΩΡΑ
     *   σε αυτή την κατάσταση, που είναι ό,τι ζητά το force. Οι επηρεασμένες
     *   γραμμές ΔΕΝ θα βοηθούσαν εδώ, γιατί η MySQL μπορεί να αναφέρει 0
     *   ΑΚΟΜΑ ΚΑΙ όταν ο όρος ταίριαξε, αφού η τιμή δεν άλλαξε.
     *
     * `updated_at` παραμένει σε δεύτερο, ξεχωριστό ερώτημα -- για τον ίδιο λόγο
     * που ίσχυε ήδη πριν (βλ. ContractUpdatedAtTest): η βάση γράφει με NOW(),
     * όχι η PHP με current_time('mysql'). Τώρα φέρει και αυτό τον ίδιο όρο
     * κατάστασης, ώστε να μην ανανεώνεται το ρολόι μιας γραμμής που τελικά δεν
     * έφτασε στη στοχευμένη κατάσταση -- και δεν εκτελείται καθόλου όταν η
     * πρώτη γραφή χάθηκε.
     *
     * The extra columns pass through the writable filter, which the old inline
     * version did not do: they are internal today (`signed_at`, `signed_ip`),
     * but "internal" is a property of the callers, and callers change. That
     * filter is now WritableColumns, shared with the save path rather than
     * copied — two lists that agree today are one column away from disagreeing.
     *
     * @param string|null $expectedFrom Guard value, or null when the caller
     *                                    genuinely does not know the previous
     *                                    status (unconditional write, no race
     *                                    guard -- see above).
     * @param array<string, mixed> $extraColumns
     *
     * @return bool True when the contract is now in $status -- because this
     *              call put it there, or because it already was. False when
     *              a concurrent transition won and left it somewhere else.
     */
    public function applyTransition(
        int $contractId,
        string $status,
        ?string $expectedFrom,
        array $extraColumns = []
    ): bool {
        global $wpdb;

        if ($contractId <= 0) {
            return false;
        }

        if ($expectedFrom === null) {
            // Ο καλών δηλώνει ρητά ότι δεν ξέρει την προηγούμενη κατάσταση
            // (`from => null` στη moveTo() -- π.χ. οι διαδρομές υπογραφής,
            // βλ. docblock εκεί). Ο γράφος μεταβάσεων έχει ήδη παρακαμφθεί για
            // αυτή την κλήση (κανένα `canMoveTo()`/`CancellationGate` δεν
            // έτρεξε), άρα δεν υπάρχει τιμή να δεσμεύσουμε τη γραφή πάνω της --
            // δεσμεύοντας σε άδεια συμβολοσειρά θα απέτυχε ΠΑΝΤΑ, αφού καμία
            // πραγματική σύμβαση δεν έχει status=''. Ίδια συμπεριφορά με πριν
            // τη διόρθωση: άνευ όρων εγγραφή, καμία προστασία race εδώ.
            $wpdb->update(
                $this->table,
                ['status' => $status] + WritableColumns::filter($extraColumns),
                ['id' => $contractId]
            );

            $wpdb->query($wpdb->prepare(
                'UPDATE %i SET updated_at = NOW() WHERE id = %d',
                $this->table,
                $contractId
            ));

            return true;
        }

        $updated = $wpdb->update(
            $this->table,
            ['status' => $status] + WritableColumns::filter($extraColumns),
            ['id' => $contractId, 'status' => $expectedFrom]
        );

        if ($status === $expectedFrom) {
            // Force-χωρίς-αλλαγή: η τιμή μένει ίδια, οπότε οι επηρεασμένες
            // γραμμές του update() παραπάνω μπορεί να είναι 0 ΑΚΟΜΑ ΚΑΙ όταν ο
            // όρος ταίριαξε -- δεν υπάρχει εδώ πραγματικός ανταγωνισμός ανάμεσα
            // σε δύο ΔΙΑΦΟΡΕΤΙΚΕΣ τιμές. Το μόνο που ρωτάμε είναι αν η γραμμή
            // είναι ΤΩΡΑ σε αυτή την κατάσταση.
            $applied = $this->statusOf($contractId) === $status;
        } else {
            // Εδώ η νέα τιμή είναι πάντα διαφορετική από το $expectedFrom, άρα
            // οι επηρεασμένες γραμμές είναι αξιόπιστο σήμα: >0 σημαίνει ότι ο
            // όρος ταίριαξε ΚΑΙ η τιμή άλλαξε πραγματικά -- δική μας νίκη.
            // 0 σημαίνει ότι κάποιος άλλος είχε ήδη μετακινήσει τη σύμβαση
            // αλλού -- ΑΝΕΞΑΡΤΗΤΑ αν το τελικό status τυχαίνει να συμπίπτει με
            // το δικό μας target (statusOf() εδώ θα έδινε ψευδώς θετικό στην
            // περίπτωση όπου κάποιος άλλος moveTo() κέρδισε τον ΙΔΙΟ στόχο --
            // η moveTo() το ξαναελέγχει η ίδια, ρητά, όταν applyTransition()
            // επιστρέψει false, ώστε αυτή η μέθοδος να μην κρύψει την ήττα).
            $applied = ((int) $updated) > 0;
        }

        if (! $applied) {
            return false;
        }

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET updated_at = NOW() WHERE id = %d AND status = %s',
            $this->table,
            $contractId,
            $status
        ));

        return true;
    }

    /**
     * Contracts still sitting in `signed` whose signature is older than the cutoff.
     *
     * The cutoff is site-local time, because `signed_at` is written with
     * current_time('mysql'). Comparing against UTC would quietly do nothing for
     * as many hours as the site is offset by.
     *
     * @return list<int>
     */
    public function idsSignedBefore(string $cutoffLocalTime, int $onlyId = 0, int $limit = 200): array
    {
        global $wpdb;

        $onlyClause = $onlyId > 0 ? ' AND id = %d' : '';
        $params     = $onlyId > 0
            ? [$this->table, $cutoffLocalTime, $onlyId, $limit]
            : [$this->table, $cutoffLocalTime, $limit];

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<string> $ids */
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM %i
                  WHERE status = 'signed' AND signed_at IS NOT NULL AND signed_at <= %s{$onlyClause}
                  LIMIT %d",
                $params
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return array_values(array_map('intval', $ids));
    }
}
