<?php

/**
 * Finds and rewrites the rows that were stored before encryption was switched on.
 *
 * ECRM_ENCRYPT_PII only ever governed *new* writes. Everything already in the
 * table stayed readable plaintext, which meant the whole FieldCipher effort
 * protected the rows nobody had filed yet and none of the rows that exist. This
 * is the other half.
 *
 * ## Why the two tables need different questions
 *
 * A customer column either starts with the marker prefix or it does not, so
 * "still to do" is a WHERE clause. The extras bag hides its personal values
 * inside a JSON document under keys the classifier decides at runtime, so SQL
 * cannot ask the question at all — those rows are walked once, in id order,
 * behind a cursor.
 *
 * ## Why walking the contracts once is enough
 *
 * The cursor only works because the sweep runs with encryption *on*: every
 * write below the cursor has already gone through ContractFields::forStorage()
 * and stored ciphertext. Run it with the flag off and the cursor would march
 * over rows that were never encrypted and never revisited — which is exactly
 * why PiiBackfill refuses to start in that state.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Infrastructure\FieldCipher;

final class PiiBackfillRepository
{
    /** Where the contracts walk has reached. Contracts only; customers self-report. */
    public const CURSOR_OPTION = 'ecrm_pii_backfill_contract_cursor';

    public function __construct(
        private readonly CustomerFields $customers,
        private readonly ContractFields $contracts,
    ) {
    }

    /** Wired from the wp-config salts, like every other holder of the cipher. */
    public static function default(): self
    {
        return new self(CustomerFields::default(), ContractFields::default());
    }

    /**
     * Customers with at least one plaintext value left in an encrypted column.
     *
     * The OR across every column is the point, not thoroughness for its own
     * sake. On the development database only 2 of 35 rows carry an ΑΦΜ while 27
     * carry an ΑΔΤ: a sweep that selected on the ΑΦΜ alone would have finished
     * after two rows and reported success.
     */
    public function pendingCustomers(): int
    {
        global $wpdb;

        $table = Tables::name(Tables::CUSTOMERS);

        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE " . self::plaintextClause());
    }

    /**
     * One slice of customers, oldest first.
     *
     * No cursor here: a row drops out of the clause the moment it is written,
     * so the next call sees the next slice. An interrupted run resumes by
     * asking the same question again.
     *
     * @return list<array<string, mixed>>
     */
    public function customersToDo(int $limit): array
    {
        global $wpdb;

        $table   = Tables::name(Tables::CUSTOMERS);
        $columns = '`id`, `' . implode('`, `', CustomerFields::encryptedColumns()) . '`';

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>>|null $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$columns} FROM `{$table}` WHERE " . self::plaintextClause() . ' ORDER BY id ASC LIMIT %d',
                $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows ?? [];
    }

    /**
     * Encrypt one customer row. Returns whether anything was written.
     *
     * Goes through CustomerFields::encryptStoredColumns(), which is the only
     * path that leaves `afm_hash` alone. forStorage() would hash the ciphertext
     * and overwrite a correct index with one that matches nothing — and a
     * duplicate check that matches nothing reads as "no duplicate".
     *
     * @param array<string, mixed> $row
     */
    public function encryptCustomer(array $row): bool
    {
        global $wpdb;

        $changes = $this->customers->encryptStoredColumns($row);

        if ($changes === []) {
            return false;
        }

        $wpdb->update(Tables::name(Tables::CUSTOMERS), $changes, ['id' => (int) $row['id']]);

        return true;
    }

    /**
     * The next contracts after the cursor, whether or not they need work.
     *
     * Rows with an empty bag are included deliberately: they still have to move
     * the cursor past themselves, or the walk stalls on the first one.
     *
     * @return list<array{id: int, extra_json: string}>
     */
    public function contractsAfter(int $cursor, int $limit): array
    {
        global $wpdb;

        $table = Tables::name(Tables::CONTRACTS);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array{id: string, extra_json: string|null}>|null $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, extra_json FROM `{$table}` WHERE id > %d ORDER BY id ASC LIMIT %d",
                $cursor,
                $limit
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return array_map(
            static fn (array $row): array => [
                'id'         => (int) $row['id'],
                'extra_json' => (string) ($row['extra_json'] ?? ''),
            ],
            $rows ?? []
        );
    }

    /**
     * Encrypt the personal values in one bag. Returns whether anything changed.
     *
     * @param array{id: int, extra_json: string} $row
     */
    public function encryptContractExtras(array $row): bool
    {
        global $wpdb;

        if ($row['extra_json'] === '') {
            return false;
        }

        $encrypted = $this->contracts->encryptStoredExtras($row['extra_json']);

        if ($encrypted === null) {
            return false;
        }

        $wpdb->update(
            Tables::name(Tables::CONTRACTS),
            [ContractFields::EXTRAS_COLUMN => $encrypted],
            ['id' => $row['id']]
        );

        return true;
    }

    /** The highest contract id, so the walk knows where it ends. */
    public function highestContractId(): int
    {
        global $wpdb;

        $table = Tables::name(Tables::CONTRACTS);

        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM `{$table}`");
    }

    public function cursor(): int
    {
        return (int) get_option(self::CURSOR_OPTION, 0);
    }

    /**
     * Move the cursor.
     *
     * autoload 'no': read once an hour by cron, never on a page load, so there
     * is no reason for it to ride along in every request's option cache.
     */
    public function moveCursor(int $id): void
    {
        update_option(self::CURSOR_OPTION, $id, false);
    }

    /**
     * `column IS NOT NULL AND column <> '' AND column NOT LIKE 'ecrm1:%'`, ORed.
     *
     * NOT LIKE is false for NULL rather than true, so the null check is not
     * decoration — without it every row with a null column drops out of the
     * result and the sweep reports itself finished early.
     */
    private static function plaintextClause(): string
    {
        $marker = FieldCipher::PREFIX . '%';
        $parts  = [];

        foreach (CustomerFields::encryptedColumns() as $column) {
            $parts[] = sprintf(
                "(`%s` IS NOT NULL AND `%s` <> '' AND `%s` NOT LIKE '%s')",
                $column,
                $column,
                $column,
                $marker
            );
        }

        return '(' . implode(' OR ', $parts) . ')';
    }
}
