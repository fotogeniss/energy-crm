<?php

/**
 * The people reporting directly to a partner.
 *
 * Distinct from NetworkRepository, which answers "everyone beneath me at any
 * depth" for visibility. This one is about the immediate team a manager
 * administers: one level down, editable, with contract counts.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use WP_User;

final class TeamRepository
{
    public const PARENT_META   = 'ecrm_parent';
    public const DISABLED_META = 'ecrm_disabled';

    /**
     * Direct reports, ordered by name.
     *
     * @return list<WP_User>
     */
    public function directReportsOf(int $userId): array
    {
        /** @var list<WP_User> $users */
        $users = get_users([
            'meta_key'   => self::PARENT_META,
            'meta_value' => $userId,
            'orderby'    => 'display_name',
        ]);

        return $users;
    }

    public function reportsDirectlyTo(int $memberId, int $managerId): bool
    {
        return (int) get_user_meta($memberId, self::PARENT_META, true) === $managerId;
    }

    public function isDisabled(int $userId): bool
    {
        return (bool) get_user_meta($userId, self::DISABLED_META, true);
    }

    public function setDisabled(int $userId, bool $disabled): void
    {
        update_user_meta($userId, self::DISABLED_META, $disabled ? '1' : '');
    }

    /**
     * Detach a member from the team without deleting their account.
     *
     * Their contracts stay theirs and their history survives; they simply stop
     * appearing in the manager's team and can no longer sign in.
     */
    public function detach(int $userId): void
    {
        delete_user_meta($userId, self::PARENT_META);
        $this->setDisabled($userId, true);
    }

    public function attach(int $userId, int $managerId): void
    {
        update_user_meta($userId, self::PARENT_META, $managerId);
    }

    public function directReportCount(int $userId): int
    {
        return count((array) get_users([
            'meta_key'   => self::PARENT_META,
            'meta_value' => $userId,
            'fields'     => 'ID',
        ]));
    }

    /**
     * How many contracts each of the given users owns, in one query.
     *
     * @param list<int> $userIds
     *
     * @return array<int, int>
     */
    public function contractCounts(array $userIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT partner_user_id, COUNT(*) AS total FROM %i
                 WHERE partner_user_id IN ({$placeholders})
                 GROUP BY partner_user_id",
                [Tables::name(Tables::CONTRACTS), ...$ids]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $counts = array_fill_keys($ids, 0);

        foreach ($rows as $row) {
            $counts[(int) $row['partner_user_id']] = (int) $row['total'];
        }

        return $counts;
    }
}
