<?php

/**
 * Contracts, one at a time: found, created, changed, deleted.
 *
 * The list queries — search, quickSearch, countsByStatus, expiring,
 * possibleDuplicates — moved to ContractQueries when this file passed 930 lines
 * against a limit of ~200. The cut runs between *finding many* and *changing
 * one*. Five wrappers remain below, delegating, until their callers move; they
 * are marked and they go in the next commit.
 *
 * The guarantee that used to read "nothing outside this class touches the
 * contracts table" now spans two classes, and holds the same way in both: every
 * method takes a UserScope, and the ones that deliberately do not are listed
 * and argued in one place each. The scope fragment itself is shared rather than
 * copied — see ScopeClause, and the reason it is a class.
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
    /**
     * Columns a caller may write.
     *
     * `partner_user_id` is absent on purpose: ownership changes are a distinct,
     * audited operation (`reassign()`), never a side effect of a save.
     */
    private const WRITABLE = [
        'customer_id',
        'provider_id',
        'program_id',
        'energy_type',
        'category',
        'price_type',
        'customer_type',
        'activation_type',
        'supply_number',
        'meter_number',
        'invoice_code',
        'status',
        'notes',
        'extracted_json',
        'extra_json',
        'start_date',
        'term_months',
        'end_date',
        'supply_addr_same',
        'supply_street',
        'supply_street_no',
        'supply_city',
        'supply_postal_code',
        'supply_region',
        'billing_addr_same',
        'billing_street',
        'billing_street_no',
        'billing_city',
        'billing_postal_code',
        'billing_region',
        'consent_at',
        'consent_ip',
        'signed_at',
        'signed_ip',
        'payout_id',
        'code',
    ];

    private string $table;

    /** Customer columns arrive here through joins, so they need translating too. */
    private CustomerFields $fields;

    /** And the contract's own encrypted part, the values inside extra_json. */
    private ContractFields $extras;

    /**
     * The list queries, which moved out.
     *
     * Held so the five wrappers below can delegate while their callers are
     * still pointed here. Both they and this property go when the callers move.
     */
    private ContractQueries $queries;

    public function __construct(
        ?string $table = null,
        ?CustomerFields $fields = null,
        ?ContractFields $extras = null,
        ?ContractQueries $queries = null,
    ) {
        $this->table   = $table ?? Tables::name(Tables::CONTRACTS);
        $this->fields  = $fields ?? CustomerFields::default();
        $this->extras  = $extras ?? ContractFields::default();
        $this->queries = $queries ?? new ContractQueries($this->table, $this->fields);
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

        $data = $this->extras->forStorage($this->filterWritable($data));

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

        $row = $this->extras->forStorage($this->filterWritable($data));

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
     * ---------------------------------------------------------------------
     * The unscoped five — and why they alone take no UserScope
     * ---------------------------------------------------------------------
     *
     * Everything above refuses to run without saying on whose behalf it runs.
     * These five deliberately do not, and the exception is narrow enough to
     * state exactly:
     *
     *   - The status transition is reached through ContractLifecycle, whose
     *     callers have already resolved the contract through a scoped read.
     *     Adding a second scope check there would not make it safer; it would
     *     make the caller believe the check lives here.
     *   - The automatic sweep runs from cron, on behalf of nobody. There is no
     *     actor to scope it to, which is the whole point of it existing.
     *   - The document build has both problems at once: it runs from cron, and
     *     it runs for the customer following a signing link, who is not a user
     *     of this system at all. Its two REST callers resolve the contract
     *     through findDetailed() first, so the scope check happens where there
     *     is somebody to check.
     *   - The notice subject is read to answer "who should be told, and what do
     *     we call this contract". All three of its callers are the customer:
     *     uploading a document through a tracking link, or signing. None of
     *     them is a user of this system, so there is nobody to scope to — and
     *     scoping it to the *recipient* would be backwards, since working out
     *     the recipient is what the read is for.
     *
     * The group was named for the lifecycle when it held three, and the note
     * said that a fourth was the moment to ask whether the exception was still
     * an exception. It arrived on 2026-08-10 and the question was asked. The
     * answer: the shared property was never "lifecycle", it was "runs on behalf
     * of nobody" — cron and an unauthenticated customer both. Renamed to say
     * so, because a group whose name no longer describes its members is how a
     * narrow exception turns into a general one without anybody deciding to.
     *
     * The fifth arrived on 2026-08-11 and was measured against that same test
     * before it was let in: its callers are an anonymous customer, so it has no
     * actor either. It was admitted deliberately, in preference to leaving a
     * fifth hand-written contracts+customers join in the codebase — the shape
     * that had just cost three separate PII leaks.
     *
     * What would make this stop being an exception is a member that *does*
     * have an actor. There is no such member, and adding one is the thing to
     * refuse.
     */

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
        global $wpdb;

        if ($contractId <= 0) {
            return;
        }

        $wpdb->update(
            $this->table,
            ['status' => $status, 'updated_at' => current_time('mysql')]
                + $this->filterWritable($extraColumns),
            ['id' => $contractId]
        );
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
        return $this->detailed($contractId, '', []);
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
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT c.code, c.partner_user_id,
                        cu.first_name, cu.last_name, cu.company_name
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 WHERE c.id = %d',
                [$this->table, Tables::name(Tables::CUSTOMERS), $contractId]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $row ? $this->fields->fromStorage($row) : null;
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
        [$clause, $params] = $this->scopeClause($scope, 'c');

        return $this->detailed($contractId, $clause, $params);
    }

    /**
     * The join behind findDetailed(), with the ownership clause left to the
     * caller.
     *
     * Shared rather than copied, and that is the whole point: the line that
     * closes it — `fromStorage()` on both the customer's columns and the
     * extras bag — is what turns stored ciphertext back into a ΑΦΜ. A second
     * copy of this query is a second place to forget it, which is exactly what
     * ECRM_REST::store_contract_pdf() had done.
     *
     * @param list<mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function detailed(int $contractId, string $clause, array $params): ?array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.*, p.name AS provider_name, g.name AS program_name, g.code AS program_code,
                        cu.first_name, cu.last_name, cu.father_name, cu.company_name,
                        cu.afm, cu.doy, cu.adt, cu.birth_date, cu.region, cu.city,
                        cu.street, cu.street_no, cu.postal_code, cu.phone, cu.mobile, cu.email
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 LEFT JOIN %i g  ON g.id  = c.program_id
                 WHERE c.id = %d{$clause}",
                [
                    $this->table,
                    Tables::name(Tables::CUSTOMERS),
                    Tables::name(Tables::PROVIDERS),
                    Tables::name(Tables::PROGRAMS),
                    $contractId,
                    ...$params,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $row ? $this->extras->fromStorage($this->fields->fromStorage($row)) : null;
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

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function filterWritable(array $data): array
    {
        $unknown = array_values(array_diff(array_keys($data), self::WRITABLE));

        if ($unknown !== []) {
            throw UnknownColumns::forEntity('σύμβαση', $unknown);
        }

        return $data;
    }
}
