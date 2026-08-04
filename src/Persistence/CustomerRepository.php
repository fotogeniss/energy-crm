<?php

/**
 * Customer reads and writes, scoped through the contracts that reference them.
 *
 * A customer row has no owner column of its own. Reachability is therefore
 * defined as "there exists a contract for this customer that the actor may
 * see". That join is expressed once, here, instead of being re-derived by
 * every caller.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;

final class CustomerRepository
{
    private const WRITABLE = [
        'customer_type',
        'afm',
        'doy',
        'first_name',
        'last_name',
        'father_name',
        'company_name',
        'adt',
        'birth_date',
        'region',
        'city',
        'street',
        'street_no',
        'postal_code',
        'phone',
        'mobile',
        'email',
    ];

    private string $table;

    private string $contractsTable;

    public function __construct(?string $table = null, ?string $contractsTable = null)
    {
        $this->table          = $table ?? Tables::name(Tables::CUSTOMERS);
        $this->contractsTable = $contractsTable ?? Tables::name(Tables::CONTRACTS);
    }

    /** @return array<string, mixed>|null */
    public function find(int $customerId, UserScope $scope): ?array
    {
        global $wpdb;

        if ($customerId <= 0) {
            return null;
        }

        if ($scope->isAdministrator()) {
            /** @var array<string, mixed>|null $row */
            $row = $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM %i WHERE id = %d', [$this->table, $customerId]),
                ARRAY_A
            );

            return $row ?: null;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT cu.* FROM %i cu
                 INNER JOIN %i c ON c.customer_id = cu.id
                 WHERE cu.id = %d AND c.partner_user_id IN (' . $scope->placeholders() . ')
                 LIMIT 1',
                [$this->table, $this->contractsTable, $customerId, ...$scope->userIds()]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $row ?: null;
    }

    public function isReachable(int $customerId, UserScope $scope): bool
    {
        return $this->find($customerId, $scope) !== null;
    }

    /**
     * Customers reachable by the actor, with a contract count, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function search(UserScope $scope, string $term = '', int $limit = 500): array
    {
        global $wpdb;

        $conditions = ['1 = 1'];
        $params     = [$this->table, $this->contractsTable];

        if (! $scope->isAdministrator()) {
            $conditions[] = 'c.partner_user_id IN (' . $scope->placeholders() . ')';
            $params       = [...$params, ...$scope->userIds()];
        }

        if ($term !== '') {
            $like         = '%' . $wpdb->esc_like($term) . '%';
            $conditions[] = "( CONCAT_WS(' ', cu.first_name, cu.last_name, cu.company_name) LIKE %s"
                . ' OR cu.afm LIKE %s OR cu.phone LIKE %s )';
            $params       = [...$params, $like, $like, $like];
        }

        $where = implode(' AND ', $conditions);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cu.id, cu.first_name, cu.last_name, cu.company_name,
                        cu.afm, cu.phone, cu.email,
                        COUNT(c.id) AS contracts, MAX(c.updated_at) AS last_at
                 FROM %i cu
                 JOIN %i c ON c.customer_id = cu.id
                 WHERE {$where}
                 GROUP BY cu.id
                 ORDER BY last_at DESC
                 LIMIT " . max(1, $limit),
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * Existing contracts matching a ΑΦΜ or supply number.
     *
     * Used before saving, to warn that the customer is already on file rather
     * than let a second application be raised for the same supply.
     *
     * @return list<array<string, mixed>>
     */
    public function duplicatesOf(UserScope $scope, string $afm, string $supply): array
    {
        global $wpdb;

        $match  = [];
        $params = [$this->table, $this->contractsTable];

        if (! $scope->isAdministrator()) {
            $params = [...$params, ...$scope->userIds()];
        }

        if ($afm !== '') {
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

        $scopeClause = $scope->isAdministrator()
            ? ''
            : ' c.partner_user_id IN (' . $scope->placeholders() . ') AND';

        $where = $scopeClause . ' (' . implode(' OR ', $match) . ')';

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.code, c.status, c.supply_number, cu.afm,
                        cu.first_name, cu.last_name, cu.company_name
                 FROM %i cu
                 JOIN %i c ON c.customer_id = cu.id
                 WHERE {$where}
                 ORDER BY c.id DESC
                 LIMIT 20",
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return bool True when the customer was reachable and updated.
     */
    public function update(int $customerId, UserScope $scope, array $data): bool
    {
        global $wpdb;

        $data = $this->filterWritable($data);

        if ($data === [] || ! $this->isReachable($customerId, $scope)) {
            return false;
        }

        return $wpdb->update($this->table, $data, ['id' => $customerId]) !== false;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return int The new customer id, or 0 when the insert failed.
     */
    public function create(array $data): int
    {
        global $wpdb;

        $wpdb->insert($this->table, $this->filterWritable($data));

        return (int) $wpdb->insert_id;
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
            throw UnknownColumns::forEntity('πελάτης', $unknown);
        }

        return $data;
    }
}
