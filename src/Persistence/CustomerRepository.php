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

    private CustomerFields $fields;

    public function __construct(
        ?string $table = null,
        ?string $contractsTable = null,
        ?CustomerFields $fields = null,
    ) {
        $this->table          = $table ?? Tables::name(Tables::CUSTOMERS);
        $this->contractsTable = $contractsTable ?? Tables::name(Tables::CONTRACTS);
        $this->fields         = $fields ?? CustomerFields::default();
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

            return $row ? $this->fields->fromStorage($row) : null;
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

        return $row ? $this->fields->fromStorage($row) : null;
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
            $like = '%' . $wpdb->esc_like($term) . '%';

            // The ΑΦΜ and the phone are each matched two ways because each
            // may be stored two ways. LIKE still finds a plaintext column;
            // once encrypted it matches nothing, so only a full ΑΦΜ or a full
            // phone number can still be found — through its own hash. A term
            // that is neither just hashes to something no row has, which is
            // harmless.
            $conditions[] = "( CONCAT_WS(' ', cu.first_name, cu.last_name, cu.company_name) LIKE %s"
                . ' OR cu.afm LIKE %s OR cu.phone LIKE %s OR cu.'
                . CustomerFields::INDEX_COLUMN . ' = %s OR cu.'
                . CustomerFields::PHONE_INDEX_COLUMN . ' = %s )';
            $params       = [
                ...$params,
                $like,
                $like,
                $like,
                $this->fields->index($term),
                $this->fields->index($term),
            ];
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

        return $this->fields->fromStorageAll($rows);
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
            // The hash, not the column: encryption is randomised, so the same
            // ΑΦΜ never equals itself twice. The hash is maintained whether or
            // not encryption is on, so this behaves the same either way.
            $match[]  = 'cu.' . CustomerFields::INDEX_COLUMN . ' = %s';
            $params[] = $this->fields->index($afm);
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

        // cu.id προστέθηκε 25/08 (build queue 08): η οθόνη «Επαναχρησιμοποίηση
        // πελάτη» χρειάζεται το id για να καλέσει CustomersController::show(),
        // όχι μόνο το προειδοποιητικό κείμενο που ζητούσε ο πρώτος καλών.
        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT cu.id, c.code, c.status, c.supply_number, cu.afm,
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

        return $this->fields->fromStorageAll($rows);
    }

    /**
     * The reachable customer id for a given ΑΦΜ, or 0 when none matches.
     *
     * Used before create(), so a second application for the same ΑΦΜ updates
     * the existing customer row instead of raising a duplicate one — found
     * during the 25/08 audit (build queue 03): every repeat application
     * silently created a new customer row, even though the ΑΦΜ index already
     * existed (duplicatesOf() used it only for the free-text warning banner,
     * never to actually resolve an id).
     *
     * Scoped exactly like find()/isReachable(): a ΑΦΜ already on file under a
     * DIFFERENT partner's contracts is not returned here. Returning it would
     * silently merge the new application into a customer row the caller
     * cannot otherwise see or edit, handing them a read on ΑΔΤ/address they
     * never had — the same shape of leak step 2 closed. That case is left to
     * create() a fresh row, and to duplicatesOf() to warn about on screen.
     */
    public function findIdByAfm(UserScope $scope, string $afm): int
    {
        global $wpdb;

        if ($afm === '') {
            return 0;
        }

        $index = $this->fields->index($afm);

        if ($scope->isAdministrator()) {
            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
            $id = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT id FROM %i WHERE ' . CustomerFields::INDEX_COLUMN . ' = %s LIMIT 1',
                    [$this->table, $index]
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

            return (int) $id;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT cu.id FROM %i cu
                 INNER JOIN %i c ON c.customer_id = cu.id
                 WHERE cu.' . CustomerFields::INDEX_COLUMN . ' = %s
                   AND c.partner_user_id IN (' . $scope->placeholders() . ')
                 LIMIT 1',
                [$this->table, $this->contractsTable, $index, ...$scope->userIds()]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return (int) $id;
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

        return $wpdb->update($this->table, $this->fields->forStorage($data), ['id' => $customerId]) !== false;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return int The new customer id, or 0 when the insert failed.
     */
    public function create(array $data): int
    {
        global $wpdb;

        $wpdb->insert($this->table, $this->fields->forStorage($this->filterWritable($data)));

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
