<?php

/**
 * The counts behind the dashboard, for one partner.
 *
 * Everything here is scoped to a single user id rather than a UserScope: the
 * dashboard is deliberately personal — "my day", not "my team's" — and the team
 * view lives under analytics.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class DashboardRepository
{
    /**
     * @return array{today: int, pending: int, routed: int, month: int}
     */
    public function cards(int $userId, string $todayStart, string $monthStart): array
    {
        return [
            'today'   => $this->countSince($userId, $todayStart),
            'pending' => $this->countWithStatus($userId, 'pending'),
            'routed'  => $this->countWithStatus($userId, 'routed'),
            'month'   => $this->countSince($userId, $monthStart),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byProviderSince(int $userId, string $since): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT p.name, COUNT(*) c
                 FROM %i ct LEFT JOIN %i p ON p.id = ct.provider_id
                 WHERE ct.partner_user_id = %d AND ct.created_at >= %s
                 GROUP BY ct.provider_id ORDER BY c DESC',
                Tables::name(Tables::CONTRACTS),
                Tables::name(Tables::PROVIDERS),
                $userId,
                $since
            ),
            ARRAY_A
        );

        return $rows;
    }

    /**
     * Contracts per month for a year, indexed 1-12 with gaps filled.
     *
     * @return array<int, int>
     */
    public function monthlyTotals(int $userId, int $year): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT MONTH(created_at) m, COUNT(*) c FROM %i
                 WHERE partner_user_id = %d AND YEAR(created_at) = %d
                 GROUP BY MONTH(created_at)',
                Tables::name(Tables::CONTRACTS),
                $userId,
                $year
            ),
            ARRAY_A
        );

        $monthly = array_fill(1, 12, 0);

        foreach ($rows as $row) {
            $monthly[(int) $row['m']] = (int) $row['c'];
        }

        return $monthly;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentActivity(int $userId, int $limit = 8): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT e.type, e.to_status, e.message, e.created_at, c.code
                 FROM %i e LEFT JOIN %i c ON c.id = e.contract_id
                 WHERE c.partner_user_id = %d
                 ORDER BY e.created_at DESC LIMIT %d',
                Tables::name(Tables::EVENTS),
                Tables::name(Tables::CONTRACTS),
                $userId,
                max(1, $limit)
            ),
            ARRAY_A
        );

        return $rows;
    }

    private function countSince(int $userId, string $since): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE partner_user_id = %d AND created_at >= %s',
                Tables::name(Tables::CONTRACTS),
                $userId,
                $since
            )
        );
    }

    private function countWithStatus(int $userId, string $status): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE partner_user_id = %d AND status = %s',
                Tables::name(Tables::CONTRACTS),
                $userId,
                $status
            )
        );
    }
}
