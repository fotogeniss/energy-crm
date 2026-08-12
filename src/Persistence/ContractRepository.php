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
 * Wrappers remain below for the methods whose callers still arrive here; they
 * are marked, and they go when the callers move.
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

    /** Customer columns arrive here through joins, so they need translating too. */
    private CustomerFields $fields;

    /** And the contract's own encrypted part, the values inside extra_json. */
    private ContractFields $extras;

    /**
     * The three that moved out.
     *
     * Held so the wrappers below can delegate while their callers are still
     * pointed here. They and these properties go when the callers move.
     */
    private ContractQueries $queries;

    private ContractDetails $details;

    private ContractTransitions $transitions;

    public function __construct(
        ?string $table = null,
        ?CustomerFields $fields = null,
        ?ContractFields $extras = null,
        ?ContractQueries $queries = null,
        ?ContractDetails $details = null,
        ?ContractTransitions $transitions = null,
    ) {
        $this->table       = $table ?? Tables::name(Tables::CONTRACTS);
        $this->fields      = $fields ?? CustomerFields::default();
        $this->extras      = $extras ?? ContractFields::default();
        $this->queries     = $queries ?? new ContractQueries($this->table, $this->fields);
        $this->details     = $details ?? new ContractDetails($this->table, $this->fields, $this->extras);
        $this->transitions = $transitions ?? new ContractTransitions($this->table);
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
                "SELECT id, status, activation_type, partner_user_id, customer_id
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
     * The five below take no UserScope, and the wrappers keep that shape while
     * their callers still arrive here. They now live in ContractTransitions and
     * ContractDetails, grouped by the property that admits them rather than
     * explained by a comment in the middle of this file.
     *
     * The policy — why they are allowed no actor, and the test a sixth would
     * have to pass — is in ARCHITECTURE.md under «Αναγνώσεις χωρίς actor».
     */

    /** The status a contract is in right now; '' when there is no such row. */
    public function statusOf(int $contractId): string
    {
        return $this->transitions->statusOf($contractId);
    }

    /**
     * Write the new status, and whatever columns come with it.
     *
     * `updated_at` is set here rather than left to the caller, because a status
     * change that does not touch it is a change nobody can find afterwards.
     *
     * The extra columns pass through the writable filter, which the old inline
     * version did not do: they are internal today (`signed_at`, `signed_ip`),
     * but "internal" is a property of the callers, and callers change.
     *
     * @param array<string, mixed> $extraColumns
     */
    public function applyTransition(int $contractId, string $status, array $extraColumns = []): void
    {
        $this->transitions->applyTransition($contractId, $status, $extraColumns);
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
        return $this->transitions->idsSignedBefore($cutoffLocalTime, $onlyId, $limit);
    }

    /**
     * The contract as the document builder needs it: everything the provider's
     * form prints, decrypted, with no actor to scope it to.
     *
     * Identical to findDetailed() but for the missing ownership clause — the
     * same query, the same translation back out of storage. That is the point
     * of it existing rather than the raw SQL it replaced: the stored form and
     * the downloaded one are now filled from the same row, read the same way.
     *
     * @return array<string, mixed>|null
     */
    public function detailedForDocument(int $contractId): ?array
    {
        return $this->details->forDocument($contractId);
    }

    /**
     * The five columns an in-app notice needs: who owns the contract, what it
     * is called, and what to call the customer.
     *
     * Deliberately not detailedForDocument(): this runs on every signature and
     * every document upload, which is a hot path at 20-40 concurrent requests,
     * and that one joins providers and programs to print a form.
     *
     * It still goes through fromStorage(). None of these columns is in
     * CustomerFields::ENCRYPTED today, so the call is a no-op — which is the
     * reason to make it now rather than later. The day a name or a company name
     * is encrypted, every read that went through here keeps working and only
     * the ones that skipped it start printing `ecrm1:…` into the bell. That is
     * exactly how the three leaks closed on 2026-08-10 came about.
     *
     * @return array<string, mixed>|null
     */
    public function noticeSubject(int $contractId): ?array
    {
        return $this->details->noticeSubject($contractId);
    }

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

        [$clause, $params] = $this->scopeClause($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET partner_user_id = %d WHERE id = %d{$clause}",
                [$this->table, $newOwnerId, $contractId, ...$params]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $result !== false;
    }

    /*
     * The five below are wrappers, kept only until their callers move in the
     * next commit. The work is in EnergyCRM\Persistence\ContractQueries, and
     * the notes explaining each query live there now — the versions here would
     * be a second copy to fall out of step.
     */

    /**
     * The contracts list, with the joined names the UI shows.
     *
     * @return list<array<string, mixed>>
     */
    public function search(UserScope $scope, string $status = '', string $term = '', int $limit = 200): array
    {
        return $this->queries->search($scope, $status, $term, $limit);
    }

    /**
     * Contracts already on file for a ΑΦΜ or supply number, company-wide.
     *
     * @return list<array<string, mixed>>
     */
    public function possibleDuplicates(string $afm, string $supply, int $excludeId = 0): array
    {
        return $this->queries->possibleDuplicates($afm, $supply, $excludeId);
    }

    /**
     * The top bar's global search: a few best matches across code, supply
     * number, customer name, ΑΦΜ and mobile.
     *
     * @return list<array<string, mixed>>
     */
    public function quickSearch(UserScope $scope, string $term, int $limit = 15): array
    {
        return $this->queries->quickSearch($scope, $term, $limit);
    }

    /**
     * How many contracts sit in each status, for the filter tabs.
     *
     * @return array<string, int>
     */
    public function countsByStatus(UserScope $scope): array
    {
        return $this->queries->countsByStatus($scope);
    }

    /**
     * A single contract joined with everything the detail view renders.
     *
     * @return array<string, mixed>|null
     */
    public function findDetailed(int $contractId, UserScope $scope): ?array
    {
        return $this->details->findDetailed($contractId, $scope);
    }

    /**
     * Contracts whose term ends within the given window.
     *
     * Drafts and cancellations are excluded: neither is up for renewal.
     *
     * @return list<array<string, mixed>>
     */
    public function expiring(UserScope $scope, int $withinDays): array
    {
        return $this->queries->expiring($scope, $withinDays);
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
