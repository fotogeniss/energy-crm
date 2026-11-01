<?php

/**
 * (346) Οι γραμμές του «Ιστορικού» στην καρτέλα πελάτη.
 *
 * Μόνο SQL. Το πώς διαβάζεται κάθε γραμμή το αποφασίζει το
 * Domain\Customer\CustomerHistory. Καμία μέθοδος εδώ δεν παίρνει scope: όλες
 * παίρνουν λίστα συμβάσεων που ο καλών έχει ΗΔΗ φέρει μέσα από scoped
 * ανάγνωση (ContractQueries::forCustomer) — ίδια λογική με το
 * EventRepository. Οι εργασίες, που έχουν δικό τους κανόνα ορατότητας, είναι
 * στο TaskRepository::forCustomerHistory().
 *
 * Κάθε ερώτημα έχει όριο: ένα ιστορικό είναι για να διαβαστεί, όχι για να
 * εξαχθεί.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class CustomerHistoryQueries
{
    private const LIMIT = 500;

    /**
     * @param list<int> $contractIds
     *
     * @return list<array<string, mixed>>
     */
    public function events(array $contractIds): array
    {
        return $this->select(
            'SELECT contract_id, user_id, type, from_status, to_status, message, reason, created_at',
            Tables::EVENTS,
            $contractIds
        );
    }

    /**
     * Χωρίς path, όνομα αρχείου ή σύνδεσμο: η γραμμή λέει «ανέβηκε», όχι «κατέβασέ το».
     *
     * @param list<int> $contractIds
     *
     * @return list<array<string, mixed>>
     */
    public function files(array $contractIds): array
    {
        return $this->select('SELECT contract_id, doc_kind, created_at', Tables::FILES, $contractIds);
    }

    /**
     * Η επαφή από την οποία βγήκε κάθε σύμβαση — η «αρχή» του πελάτη.
     *
     * @param list<int> $contractIds
     *
     * @return list<array<string, mixed>>
     */
    public function leadOrigins(array $contractIds): array
    {
        return $this->select('SELECT contract_id, partner_user_id, source, created_at', Tables::LEADS, $contractIds);
    }

    /**
     * @param list<int> $contractIds
     *
     * @return list<array<string, mixed>>
     */
    private function select(string $columns, string $table, array $contractIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $contractIds))));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "{$columns} FROM %i WHERE contract_id IN ({$placeholders})
                 ORDER BY created_at DESC LIMIT " . self::LIMIT,
                [Tables::name($table), ...$ids]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }
}
