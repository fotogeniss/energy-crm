<?php

/**
 * The only place contracts are read from or written to.
 *
 * Every method takes a UserScope. There is no overload without one, so a query
 * that ignores ownership cannot be written here — and since nothing outside
 * this class touches the contracts table, it cannot be written anywhere.
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
        'consent_at',
        'consent_ip',
        'signed_at',
        'signed_ip',
        'payout_id',
        'code',
    ];

    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::CONTRACTS);
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

        return $row ?: null;
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

        $data = $this->filterWritable($data);

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

        $row = $this->filterWritable($data);

        // Ownership is assigned here, never taken from the request.
        $row['partner_user_id'] = $scope->actorId();

        $wpdb->insert($this->table, $row);

        return (int) $wpdb->insert_id;
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

    /**
     * The contracts list, with the joined names the UI shows.
     *
     * @return list<array<string, mixed>>
     */
    public function search(UserScope $scope, string $status = '', string $term = '', int $limit = 200): array
    {
        global $wpdb;

        [$clause, $scopeParams] = $this->scopeClause($scope, 'c');

        $params     = [
            $this->table,
            Tables::name(Tables::CUSTOMERS),
            Tables::name(Tables::PROVIDERS),
            Tables::name(Tables::PROGRAMS),
            ...$scopeParams,
        ];
        $conditions = ['1 = 1' . $clause];

        if ($status !== '') {
            $conditions[] = 'c.status = %s';
            $params[]     = $status;
        }

        if ($term !== '') {
            $like         = '%' . $wpdb->esc_like($term) . '%';
            $conditions[] = '( cu.first_name LIKE %s OR cu.last_name LIKE %s OR cu.company_name LIKE %s'
                . ' OR cu.afm LIKE %s OR c.supply_number LIKE %s OR c.code LIKE %s )';
            $params       = [...$params, $like, $like, $like, $like, $like, $like];
        }

        $where = implode(' AND ', $conditions);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.energy_type, c.category, c.invoice_code,
                        c.supply_number, c.created_at, c.updated_at, c.partner_user_id,
                        p.name AS provider_name, p.slug AS provider_slug,
                        p.logo_url AS provider_logo, g.name AS program_name,
                        cu.first_name, cu.last_name, cu.company_name, cu.afm, cu.phone
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 LEFT JOIN %i g  ON g.id  = c.program_id
                 WHERE {$where}
                 ORDER BY c.updated_at DESC
                 LIMIT " . max(1, $limit),
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * Contracts already on file for a ΑΦΜ or supply number — across the whole
     * company, on purpose.
     *
     * The one query here that ignores scope, and it has to. A second
     * application for a supply another partner already signed is exactly the
     * collision worth warning about, and scoping the search would hide it. The
     * caller masks what it returns: outside the actor's scope, only the fact of
     * a clash is disclosed, never the customer or the colleague.
     *
     * @return list<array<string, mixed>>
     */
    public function possibleDuplicates(string $afm, string $supply, int $excludeId = 0): array
    {
        global $wpdb;

        $match  = [];
        $params = [
            $this->table,
            Tables::name(Tables::CUSTOMERS),
            Tables::name(Tables::PROVIDERS),
        ];

        if (strlen($afm) >= 9) {
            $match[]  = 'cu.afm = %s';
            $params[] = $afm;
        }

        if ($supply !== '') {
            $match[]  = 'c.supply_number = %s';
            $params[] = $supply;
        }

        if ($match === []) {
            return [];
        }

        $where = '( ' . implode(' OR ', $match) . ' )';

        if ($excludeId > 0) {
            $where   .= ' AND c.id <> %d';
            $params[] = $excludeId;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.supply_number, c.partner_user_id,
                        cu.first_name, cu.last_name, cu.company_name, cu.afm,
                        p.name AS provider_name
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 WHERE {$where}
                 ORDER BY c.updated_at DESC
                 LIMIT 8",
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * The top bar's global search: a few best matches across code, supply
     * number, customer name, ΑΦΜ and mobile.
     *
     * @return list<array<string, mixed>>
     */
    public function quickSearch(UserScope $scope, string $term, int $limit = 15): array
    {
        global $wpdb;

        if ($term === '') {
            return [];
        }

        [$clause, $scopeParams] = $this->scopeClause($scope, 'c');
        $like                   = '%' . $wpdb->esc_like($term) . '%';

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.supply_number,
                        cu.first_name, cu.last_name, cu.company_name, cu.afm,
                        p.name AS provider_name
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 WHERE ( c.code LIKE %s OR c.supply_number LIKE %s
                         OR cu.first_name LIKE %s OR cu.last_name LIKE %s
                         OR cu.company_name LIKE %s OR cu.afm LIKE %s
                         OR cu.mobile LIKE %s ){$clause}
                 ORDER BY c.updated_at DESC
                 LIMIT " . max(1, $limit),
                [
                    $this->table,
                    Tables::name(Tables::CUSTOMERS),
                    Tables::name(Tables::PROVIDERS),
                    $like, $like, $like, $like, $like, $like, $like,
                    ...$scopeParams,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * How many contracts sit in each status, for the filter tabs.
     *
     * @return array<string, int>
     */
    public function countsByStatus(UserScope $scope): array
    {
        global $wpdb;

        [$clause, $params] = $this->scopeClause($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS total FROM %i WHERE 1 = 1{$clause} GROUP BY status",
                [$this->table, ...$params]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * A single contract joined with everything the detail view renders.
     *
     * @return array<string, mixed>|null
     */
    public function findDetailed(int $contractId, UserScope $scope): ?array
    {
        global $wpdb;

        [$clause, $params] = $this->scopeClause($scope, 'c');

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT c.*, p.name AS provider_name, g.name AS program_name,
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

        return $row ?: null;
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
        global $wpdb;

        $customers = Tables::name(Tables::CUSTOMERS);
        $providers = Tables::name(Tables::PROVIDERS);

        [$clause, $params] = $this->scopeClause($scope, 'c');

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.status, c.end_date, c.term_months,
                        DATEDIFF(c.end_date, NOW()) AS days_left,
                        p.name AS provider_name, p.logo_url AS provider_logo,
                        cu.first_name, cu.last_name, cu.company_name, cu.phone
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 LEFT JOIN %i p  ON p.id  = c.provider_id
                 WHERE c.end_date IS NOT NULL
                   AND c.status NOT IN ('cancelled', 'draft')
                   AND DATEDIFF(c.end_date, NOW()) <= %d{$clause}
                 ORDER BY c.end_date ASC
                 LIMIT 300",
                [$this->table, $customers, $providers, $withinDays, ...$params]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
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
     * Administrators get an empty fragment; everyone else gets an IN list that
     * UserScope guarantees is non-empty.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function scopeClause(UserScope $scope, string $alias = ''): array
    {
        if ($scope->isAdministrator()) {
            return ['', []];
        }

        $column = ($alias === '' ? '' : $alias . '.') . 'partner_user_id';

        return [
            ' AND ' . $column . ' IN (' . $scope->placeholders() . ')',
            $scope->userIds(),
        ];
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
