<?php

/**
 * Everything we hold about one customer, handed back to them.
 *
 * GDPR Article 15: a data subject may ask for a copy of their data, and the
 * answer has to be complete. This used to return the customer row, their
 * contracts, document metadata and the event log — leaving out the signature
 * they drew, the lead their first phone call became, and the tasks and
 * notifications written about them. All of it is data about the person; none
 * of it was disclosed.
 *
 * The tables come from PersonalDataTables, the same list the eraser answers
 * to, so the two cannot drift apart again.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class PersonalDataExporter
{
    private CustomerFields $fields;

    private ContractFields $extras;

    public function __construct(?CustomerFields $fields = null, ?ContractFields $extras = null)
    {
        $this->fields = $fields ?? CustomerFields::default();
        $this->extras = $extras ?? ContractFields::default();
    }

    /**
     * The subject's file, or null when no such customer exists.
     *
     * @return array<string, mixed>|null
     */
    public function export(int $customerId): ?array
    {
        if ($customerId <= 0) {
            return null;
        }

        $customer = $this->customer($customerId);

        if ($customer === null) {
            return null;
        }

        $contracts   = $this->contracts($customerId);
        $contractIds = array_values(array_filter(array_map(
            static fn (array $contract): int => (int) ($contract['id'] ?? 0),
            $contracts
        )));

        $subject = ['customer' => $customer, 'contracts' => $contracts];

        // customer_notes.customer_id δεν χωράει στο linkedToContracts() (εκείνο
        // είναι κλειδωμένο σε contract_id) -- ίδια κατηγορία ακμής με το
        // tasks.customer_id λίγο πιο κάτω, δηλωμένη στο
        // PersonalDataCoverageTest::HANDLED_INLINE.
        $subject[Tables::CUSTOMER_NOTES] = $this->rowsFor(Tables::CUSTOMER_NOTES, 'customer_id', [$customerId]);

        foreach (PersonalDataTables::linkedToContracts() as $table => $keyColumn) {
            $rows = $this->rowsFor($table, $keyColumn, $contractIds);

            // A task also reaches a customer directly. Merged rather than
            // listed apart, because that distinction is ours and means nothing
            // to the person reading the file.
            if ($table === Tables::TASKS) {
                $rows = $this->mergeById(
                    $rows,
                    $this->rowsFor(Tables::TASKS, 'customer_id', [$customerId])
                );
            }

            $subject[$table] = $rows;
        }

        return $subject;
    }

    /**
     * Which customers a privacy request is about.
     *
     * WordPress identifies a data subject by email address, so this is the
     * step that turns "someone wrote to us from this address" into rows we
     * can act on. Deliberately unscoped: a privacy request concerns the whole
     * company, not the network branch of whoever happens to be logged in — and
     * it is only ever reached from capability-gated admin screens.
     *
     * More than one row can share an address (a household, a re-registration),
     * so this returns all of them rather than assuming the first is the one.
     *
     * @return list<int>
     */
    public function subjectIdsByEmail(string $email): array
    {
        global $wpdb;

        $email = trim($email);

        if ($email === '' || ! is_email($email)) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE email = %s ORDER BY id',
                Tables::name(Tables::CUSTOMERS),
                $email
            )
        );

        return array_values(array_filter(array_map('intval', $ids)));
    }

    /** @return array<string, mixed>|null */
    private function customer(int $customerId): ?array
    {
        global $wpdb;

        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d',
                Tables::name(Tables::CUSTOMERS),
                $customerId
            ),
            ARRAY_A
        );

        // Handing someone their own file with the encrypted columns still
        // encrypted would be a wrong answer dressed as a complete one.
        return $row === null ? null : $this->fields->fromStorage($row);
    }

    /** @return list<array<string, mixed>> */
    private function contracts(int $customerId): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE customer_id = %d ORDER BY id',
                Tables::name(Tables::CONTRACTS),
                $customerId
            ),
            ARRAY_A
        );

        return $this->extras->fromStorageAll($rows);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function rowsFor(string $unprefixedTable, string $keyColumn, array $ids): array
    {
        global $wpdb;

        if ($ids === []) {
            return [];
        }

        $columns        = PersonalDataTables::disclosedColumns()[$unprefixedTable] ?? '*';
        $idPlaceholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$columns} FROM %i WHERE {$keyColumn} IN ({$idPlaceholders}) ORDER BY id",
                Tables::name($unprefixedTable),
                ...$ids
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $first
     * @param list<array<string, mixed>> $second
     *
     * @return list<array<string, mixed>>
     */
    private function mergeById(array $first, array $second): array
    {
        $byId = [];

        foreach ([...$first, ...$second] as $row) {
            $byId[(int) ($row['id'] ?? 0)] = $row;
        }

        ksort($byId);

        return array_values($byId);
    }
}
