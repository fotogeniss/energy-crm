<?php

/**
 * Per-member workload figures behind the live team screen.
 *
 * Unlike the other repositories this one has to name its members explicitly —
 * the screen lists a row per person, so "all of them" has to become concrete
 * ids at some point. It resolves them itself from the scope rather than
 * accepting a list from the caller, so an arbitrary id still cannot be smuggled
 * into the WHERE clause.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;

final class TeamActivityRepository
{
    public function __construct(private readonly NetworkRepository $network)
    {
    }

    /**
     * The people this actor may see, as concrete ids.
     *
     * An administrator runs the company, so their list is everyone; a manager's
     * is their own subtree. Always contains the actor.
     *
     * @return non-empty-list<int>
     */
    public function memberIds(UserScope $scope): array
    {
        if (! $scope->isAdministrator()) {
            return $scope->userIds();
        }

        $everyone = $this->network->allUserIds();

        return $everyone === [] ? [$scope->actorId()] : $everyone;
    }

    /**
     * Contract counts and last activity per member, keyed by user id.
     *
     * @param string $today Y-m-d in site time.
     * @param string $month Y-m-01 in site time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function contractStats(UserScope $scope, string $today, string $month): array
    {
        global $wpdb;

        $ids = $this->memberIds($scope);
        $in  = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT partner_user_id uid,
                        SUM( DATE(created_at) = %s ) today,
                        SUM( created_at >= %s ) month,
                        SUM( status = 'pending' ) pending,
                        SUM( status = 'routed' ) routed,
                        SUM( status = 'active' ) active,
                        MAX( updated_at ) last_activity
                 FROM %i WHERE partner_user_id IN ({$in})
                 GROUP BY partner_user_id",
                [$today, $month, Tables::name(Tables::CONTRACTS), ...$ids]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $this->keyByUser((array) $rows);
    }

    /**
     * Open task counts per assignee, keyed by user id.
     *
     * @return array<int, int>
     */
    public function openTaskCounts(UserScope $scope): array
    {
        global $wpdb;

        $ids = $this->memberIds($scope);
        $in  = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT assigned_to uid, COUNT(*) open_tasks FROM %i
                 WHERE status = 'open' AND assigned_to IN ({$in})
                 GROUP BY assigned_to",
                [Tables::name(Tables::TASKS), ...$ids]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $counts = [];

        foreach ((array) $rows as $row) {
            $counts[(int) $row['uid']] = (int) $row['open_tasks'];
        }

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function keyByUser(array $rows): array
    {
        $byUser = [];

        foreach ($rows as $row) {
            $byUser[(int) $row['uid']] = $row;
        }

        return $byUser;
    }
}
