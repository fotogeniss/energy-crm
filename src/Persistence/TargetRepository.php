<?php

/**
 * (345) Στόχοι αιτήσεων ανά πωλητή και μήνα.
 *
 * Ποιοι πωλητές επιτρέπεται να πάρουν στόχο το αποφασίζει ο καλών (μόνο η
 * ομάδα του υπεύθυνου)· εδώ γράφονται μόνο τα ids που δόθηκαν.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class TargetRepository
{
    /**
     * Οι στόχοι ενός μήνα για αυτούς τους πωλητές, user id => αιτήσεις.
     *
     * @param list<int> $userIds
     *
     * @return array<int, int>
     */
    public function forMonth(string $month, array $userIds): array
    {
        global $wpdb;

        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($userIds === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($userIds), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, applications FROM %i WHERE month = %s AND user_id IN ({$in})",
                [Tables::name(Tables::TARGETS), $month, ...$userIds]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $out = [];

        foreach ((array) $rows as $row) {
            $out[(int) $row['user_id']] = (int) $row['applications'];
        }

        return $out;
    }

    /**
     * Γράφει ή σβήνει. Null ή 0 = χωρίς στόχο.
     *
     * @param array<int, int|null> $targets user id => αιτήσεις
     */
    public function save(string $month, array $targets, int $setBy): void
    {
        global $wpdb;

        $table = Tables::name(Tables::TARGETS);

        foreach ($targets as $userId => $value) {
            $userId = (int) $userId;

            if ($userId <= 0) {
                continue;
            }

            if ($value === null || $value <= 0) {
                $wpdb->delete($table, ['user_id' => $userId, 'month' => $month]);
                continue;
            }

            $wpdb->query(
                $wpdb->prepare(
                    'INSERT INTO %i (user_id, month, applications, set_by) VALUES (%d, %s, %d, %d)
                     ON DUPLICATE KEY UPDATE applications = VALUES(applications), set_by = VALUES(set_by),
                                             updated_at = CURRENT_TIMESTAMP',
                    $table,
                    $userId,
                    $month,
                    $value,
                    $setBy
                )
            );
        }
    }
}
