<?php

/**
 * One contract, scoped: found, created, changed, handed over, deleted.
 *
 * What this class is left holding is the part that always needs an actor. The
 * rest of the 930 lines it used to be went to neighbours, each named for the
 * property that groups its members:
 *
 *   - ContractQueries   the lists behind the screens
 *   - ContractDetails   the joined view, and the only copy of that join
 *   - ContractTransitions the status rows and the cron sweep — no actor
 *   - WritableColumns   which columns a caller may write
 *   - ScopeClause       the ownership fragment, one copy
 *
 * The guarantee that used to read "nothing outside this class touches the
 * contracts table" now spans five, and holds the same way in each: a method
 * either takes a UserScope, or is one of the ones ARCHITECTURE.md admits under
 * «Αναγνώσεις χωρίς actor» — a list short enough to read and a test a sixth
 * would have to pass.
 *
 * Writes are scoped in the WHERE clause rather than by a preceding SELECT, so
 * the check and the write are a single statement and cannot drift apart.
 *
 * On the phpcs exemptions below: table names are bound with %i, and every
 * value is a bound parameter. What phpcs cannot verify is the `IN (%d,%d,…)`
 * fragment, whose length varies with team size. That fragment is produced by
 * UserScope::placeholders(), which emits nothing but "%d" — no request data
 * reaches it.
 *
 * The exemptions name whole categories (WordPress.DB.PreparedSQL) rather than
 * individual sniffs, because the sub-sniff that fires depends on whether the
 * fragment arrives by interpolation or concatenation, and getting that name
 * wrong silently leaves the statement unexempted. Each block wraps exactly one
 * statement, so every other query in this file is still checked.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractCode;

final class ContractRepository
{
    private string $table;

    /**
     * The contract's own encrypted part: the values inside extra_json.
     *
     * CustomerFields is not needed here any more. It was for the joined customer
     * columns, and every query that joins customers now lives in
     * ContractDetails or ContractQueries.
     */
    private ContractFields $extras;

    /**
     * Εύρημα ελέγχου ασφαλείας/λογικής (26/08/2026): η `reassign()`/`handOver()`
     * άλλαζαν `partner_user_id` με ωμό `UPDATE`, χωρίς ΚΑΝΕΝΑ αντίγραφο στο
     * `events` -- σε αντίθεση με κάθε αλλαγή κατάστασης, που περνάει πάντα από
     * το `ContractLifecycle::moveTo()` και καταγράφεται. Ποιος είχε τη σύμβαση
     * πριν από μια ανάθεση δεν ήταν ανιχνεύσιμο πουθενά.
     *
     * Προαιρετικό, με προεπιλογή δικού του instance -- ίδιο μοτίβο με το
     * `$extras` παραπάνω -- ώστε τα ήδη υπάρχοντα `new ContractRepository()`
     * (δεκάδες, σε production και tests) να συνεχίσουν να δουλεύουν αμετάβλητα.
     */
    private EventRepository $events;

    public function __construct(?string $table = null, ?ContractFields $extras = null, ?EventRepository $events = null)
    {
        $this->table  = $table ?? Tables::name(Tables::CONTRACTS);
        $this->extras = $extras ?? ContractFields::default();
        $this->events = $events ?? new EventRepository();
    }

    /** @return array<string, mixed>|null */
    public function find(int $contractId, UserScope $scope): ?array
    {
        global $wpdb;

        if ($contractId <= 0) {
            return null;
        }

        [$clause, $params] = $this->scopeClause($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE id = %d{$clause}",
                [$this->table, $contractId, ...$params]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $row ? $this->extras->fromStorage($row) : null;
    }

    public function exists(int $contractId, UserScope $scope): bool
    {
        return $this->find($contractId, $scope) !== null;
    }

    /**
     * Update a contract the actor is allowed to touch.
     *
     * @param array<string, mixed> $data
     *
     * @return bool True when a row within scope was matched.
     */
    public function update(int $contractId, UserScope $scope, array $data): bool
    {
        global $wpdb;

        if ($contractId <= 0) {
            return false;
        }

        $data = $this->extras->forStorage(WritableColumns::filter($data));

        if ($data === []) {
            return $this->exists($contractId, $scope);
        }

        $assignments = [];
        $values      = [];

        foreach ($data as $column => $value) {
            // Column names come from self::WRITABLE, never from the caller's keys.
            $assignments[] = '`' . $column . '` = ' . ($value === null ? 'NULL' : '%s');

            if ($value !== null) {
                $values[] = $value;
            }
        }

        [$clause, $scopeParams] = $this->scopeClause($scope);

        $sql = 'UPDATE %i SET ' . implode(', ', $assignments) . " WHERE id = %d{$clause}";

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $affected = $wpdb->query(
            $wpdb->prepare($sql, [$this->table, ...$values, $contractId, ...$scopeParams])
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        if ($affected === false) {
            return false;
        }

        // 0 rows can mean "outside the scope" or "nothing actually changed";
        // only a follow-up read tells the two apart.
        return $affected > 0 || $this->exists($contractId, $scope);
    }

    /**
     * Create a contract owned by the acting user.
     *
     * @param array<string, mixed> $data
     *
     * @return int The new contract id, or 0 when the insert failed.
     */
    public function create(array $data, UserScope $scope): int
    {
        global $wpdb;

        $row = $this->extras->forStorage(WritableColumns::filter($data));

        // Ownership is assigned here, never taken from the request.
        $row['partner_user_id'] = $scope->actorId();

        $wpdb->insert($this->table, $row);

        return (int) $wpdb->insert_id;
    }

    /**
     * Stamp the human reference on a freshly created contract.
     *
     * The code is derived from the row id, so it can only be written after the
     * insert. Every creation path calls this rather than formatting its own,
     * which is how the format stays identical across screens. The prefix comes
     * from the contract's own provider, so a code read aloud on the phone says
     * which provider it belongs to without anyone opening the row.
     *
     * @return string The code written, or an empty string when the row was
     *                outside the scope or the id was not real.
     */
    public function assignCode(int $contractId, UserScope $scope): string
    {
        if ($contractId <= 0) {
            return '';
        }

        $code = ContractCode::forId($contractId, $this->providerPrefix($contractId));

        return $this->update($contractId, $scope, ['code' => $code]) ? $code : '';
    }

    /** The owning provider's slug, upper-cased for the code prefix; '' when none. */
    private function providerPrefix(int $contractId): string
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $slug = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT p.slug FROM %i c LEFT JOIN %i p ON p.id = c.provider_id WHERE c.id = %d',
                [$this->table, Tables::name(Tables::PROVIDERS), $contractId]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $slug !== null ? (string) $slug : '';
    }

    /**
     * The subset of the given ids the actor may actually act on.
     *
     * Bulk operations start here: whatever the client sent, only what comes
     * back is touched. Ids outside the scope are dropped rather than refused,
     * so a stale selection does not block the rest of the batch.
     *
     * @param list<int> $contractIds
     *
     * @return list<array<string, mixed>>
     */
    public function reachableAmong(array $contractIds, UserScope $scope): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $contractIds))));

        if ($ids === []) {
            return [];
        }

        [$clause, $scopeParams] = $this->scopeClause($scope);
        $placeholders           = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, status, activation_type, energy_type, partner_user_id, customer_id
                 FROM %i WHERE id IN ({$placeholders}){$clause}",
                [$this->table, ...$ids, ...$scopeParams]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * Delete several contracts at once, within scope.
     *
     * @param list<int> $contractIds
     *
     * @return int Rows removed.
     */
    public function deleteMany(array $contractIds, UserScope $scope): int
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $contractIds))));

        if ($ids === []) {
            return 0;
        }

        [$clause, $scopeParams] = $this->scopeClause($scope);
        $placeholders           = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $affected = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE id IN ({$placeholders}){$clause}",
                [$this->table, ...$ids, ...$scopeParams]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $affected === false ? 0 : (int) $affected;
    }

    /**
     * Delete a contract the actor may reach.
     *
     * Documents must be purged first: the row is the only pointer to the file
     * on disk, and the foreign key will take the row away without touching the
     * bytes. See FileRepository::purgeForContracts().
     */
    public function delete(int $contractId, UserScope $scope): bool
    {
        global $wpdb;

        [$clause, $params] = $this->scopeClause($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $affected = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE id = %d{$clause}",
                [$this->table, $contractId, ...$params]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $affected !== false && $affected > 0;
    }

    /** Owner of a contract, or null when it is outside the scope. */
    public function ownerId(int $contractId, UserScope $scope): ?int
    {
        $row = $this->find($contractId, $scope);

        return $row === null ? null : (int) $row['partner_user_id'];
    }

    /*
     * The eleven wrappers that stood here are gone. Their callers now hold the
     * narrower collaborator they actually needed:
     *
     *   ContractQueries      search, quickSearch, countsByStatus, expiring,
     *                        possibleDuplicates
     *   ContractDetails      findDetailed, forDocument, noticeSubject
     *   ContractTransitions  statusOf, applyTransition, idsSignedBefore
     *
     * Five of the six controllers that came through here turned out to use
     * nothing else from this class, so they no longer receive it at all — they
     * had been handed create(), update(), delete() and reassign() in order to
     * run a search.
     */

    /**
     * Move a contract to another partner. Both the contract and the new owner
     * must sit inside the acting user's scope.
     */
    public function reassign(int $contractId, int $newOwnerId, UserScope $scope): bool
    {
        global $wpdb;

        if (! $scope->includes($newOwnerId) || ! $this->exists($contractId, $scope)) {
            return false;
        }

        $previousOwnerId = $this->ownerId($contractId, $scope);

        [$clause, $params] = $this->scopeClause($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET partner_user_id = %d WHERE id = %d{$clause}",
                [$this->table, $newOwnerId, $contractId, ...$params]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $moved = $result !== false;

        if ($moved) {
            $this->events->record($contractId, $scope->actorId(), 'reassigned', [
                'message' => 'Ανάθεση' . $this->fromToNames($previousOwnerId, $newOwnerId),
            ]);
        }

        return $moved;
    }

    /** ' από X' / ' σε Y' -- ό,τι από τα δύο υπάρχει ακόμα ως λογαριασμός WordPress. */
    private function fromToNames(?int $fromUserId, int $toUserId): string
    {
        $from = $fromUserId !== null ? get_userdata($fromUserId) : false;
        $to   = get_userdata($toUserId);

        $bits = [];

        if ($from) {
            $bits[] = 'από ' . $from->display_name;
        }

        if ($to) {
            $bits[] = 'σε ' . $to->display_name;
        }

        return $bits === [] ? '' : ' ' . implode(' ', $bits) . '.';
    }

    /**
     * Δίνει όλες τις συμβάσεις ενός συνεργάτη σε άλλον.
     *
     * Η `reassign()` μετακινεί μία σύμβαση επειδή κάποιος το ζήτησε για αυτήν.
     * Αυτή μετακινεί ένα ολόκληρο χαρτοφυλάκιο επειδή ο άνθρωπος έφυγε, και οι
     * δύο πλευρές ελέγχονται με το ίδιο κριτήριο: και ο παλιός και ο νέος
     * κάτοχος πρέπει να είναι μέσα στο scope αυτού που το ζητά.
     *
     * **Δεν αγγίζει τα λεφτά που έχουν ήδη πληρωθεί, και δεν χρειάζεται.** Η
     * γραμμή της εκκαθάρισης κρατά δικό της `partner_user_id`, οπότε η παλιά
     * βεβαίωση τυπώνεται σωστά με το όνομα που πληρώθηκε· και η
     * `ECRM_Payouts::unsettled_rows()` διαλέγει μόνο `payout_id IS NULL`, οπότε
     * ο νέος κάτοχος δεν πληρώνεται δεύτερη φορά για ό,τι εκκαθαρίστηκε ήδη.
     *
     * @return int Πόσες συμβάσεις μετακινήθηκαν.
     */
    public function handOver(int $fromUserId, int $toUserId, UserScope $scope): int
    {
        global $wpdb;

        if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId) {
            return 0;
        }

        if (! $scope->includes($fromUserId) || ! $scope->includes($toUserId)) {
            return 0;
        }

        [$clause, $params] = $this->scopeClause($scope);

        // Ποιες συμβάσεις μετακινούνται -- μόνο για να καταγραφεί το γεγονός σε
        // καθεμιά τους μετά· η ίδια η μετακίνηση αποφασίζεται αποκλειστικά από
        // το παρακάτω UPDATE, όχι από αυτή τη λίστα.
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $movingIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM %i WHERE partner_user_id = %d{$clause}",
                [$this->table, $fromUserId, ...$params]
            )
        );

        $moved = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET partner_user_id = %d WHERE partner_user_id = %d{$clause}",
                [$this->table, $toUserId, $fromUserId, ...$params]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        if ($moved === false) {
            return 0;
        }

        $message = 'Μεταφορά χαρτοφυλακίου' . $this->fromToNames($fromUserId, $toUserId);

        foreach ($movingIds as $contractId) {
            $this->events->record((int) $contractId, $scope->actorId(), 'reassigned', ['message' => $message]);
        }

        return (int) $moved;
    }

    /**
     * Clear extraction payloads older than the retention period.
     *
     * Deliberately unscoped: this is a scheduled maintenance sweep with no
     * actor behind it, and it is meant to touch every row that qualifies. It
     * is the one method here that does not take a UserScope, which is safe
     * because it only ever nulls a column and never reads or moves data.
     *
     * @return int Number of contracts cleared.
     */
    public function clearExtractionPayloads(int $olderThanDays): int
    {
        global $wpdb;

        if ($olderThanDays <= 0) {
            return 0;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $cleared = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET extracted_json = NULL
                 WHERE extracted_json IS NOT NULL
                   AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $olderThanDays
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $cleared === false ? 0 : (int) $cleared;
    }

    /**
     * SQL fragment restricting rows to the scope, plus its bound values.
     *
     * The body moved to ScopeClause when the list queries left, because two
     * classes needed it and a copied authorization clause is a second place to
     * get it quietly wrong. This stays as the name the rest of the file already
     * calls.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function scopeClause(UserScope $scope, string $alias = ''): array
    {
        return ScopeClause::forScope($scope, $alias);
    }
}
