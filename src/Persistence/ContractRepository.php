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
     * SQL fragment restricting rows to the scope, plus its bound values.
     *
     * Administrators get an empty fragment; everyone else gets an IN list that
     * UserScope guarantees is non-empty.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function scopeClause(UserScope $scope): array
    {
        if ($scope->isAdministrator()) {
            return ['', []];
        }

        return [
            ' AND partner_user_id IN (' . $scope->placeholders() . ')',
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
