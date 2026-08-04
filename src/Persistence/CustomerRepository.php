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
