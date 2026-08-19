<?php

/**
 * The aggregate queries behind the analytics screen.
 *
 * Read-only and scope-bound like everything else. Each method answers one
 * question, so the controller reads as a list of questions rather than a wall
 * of SQL.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;

final class AnalyticsRepository
{
    /**
     * @return array<string, int>
     */
    public function countsByStatus(UserScope $scope): array
    {
        [$clause, $params] = $this->scopeClause($scope);

        $rows = $this->rows(
            "SELECT status, COUNT(*) c FROM %i WHERE 1 = 1{$clause} GROUP BY status",
            [Tables::name(Tables::CONTRACTS), ...$params]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * Average days from creation to the first move into "active".
     *
     * Null when nothing has been activated yet, which is different from zero
     * and must stay so: zero would read as "activated the same day".
     */
    public function averageDaysToActivation(UserScope $scope): ?float
    {
        global $wpdb;

        [$clause, $params] = $this->scopeClause($scope, 'c');

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $average = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG(DATEDIFF(e.activated, c.created_at))
                 FROM %i c
                 JOIN ( SELECT contract_id, MIN(created_at) activated FROM %i
                        WHERE to_status = 'active' GROUP BY contract_id ) e
                   ON e.contract_id = c.id
                 WHERE 1 = 1{$clause}",
                [Tables::name(Tables::CONTRACTS), Tables::name(Tables::EVENTS), ...$params]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $average === null ? null : round((float) $average, 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topProviders(UserScope $scope, int $limit = 8): array
    {
        [$clause, $params] = $this->scopeClause($scope, 'ct');

        return $this->rows(
            "SELECT p.name, COUNT(*) c
             FROM %i ct LEFT JOIN %i p ON p.id = ct.provider_id
             WHERE 1 = 1{$clause}
             GROUP BY ct.provider_id ORDER BY c DESC LIMIT " . max(1, $limit),
            [Tables::name(Tables::CONTRACTS), Tables::name(Tables::PROVIDERS), ...$params]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byEnergyType(UserScope $scope): array
    {
        [$clause, $params] = $this->scopeClause($scope);

        return $this->rows(
            "SELECT energy_type, COUNT(*) c FROM %i WHERE 1 = 1{$clause}
             GROUP BY energy_type ORDER BY c DESC",
            [Tables::name(Tables::CONTRACTS), ...$params]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topRegions(UserScope $scope, int $limit = 8): array
    {
        [$clause, $params] = $this->scopeClause($scope, 'ct');

        return $this->rows(
            "SELECT COALESCE(NULLIF(cu.region, ''), '—') name, COUNT(*) c
             FROM %i ct LEFT JOIN %i cu ON cu.id = ct.customer_id
             WHERE 1 = 1{$clause}
             GROUP BY name ORDER BY c DESC LIMIT " . max(1, $limit),
            [Tables::name(Tables::CONTRACTS), Tables::name(Tables::CUSTOMERS), ...$params]
        );
    }

    /**
     * @return array<int, int>
     */
    public function monthlyTotals(UserScope $scope, int $year): array
    {
        [$clause, $params] = $this->scopeClause($scope);

        $rows = $this->rows(
            "SELECT MONTH(created_at) m, COUNT(*) c FROM %i
             WHERE YEAR(created_at) = %d{$clause} GROUP BY MONTH(created_at)",
            [Tables::name(Tables::CONTRACTS), $year, ...$params]
        );

        $monthly = array_fill(1, 12, 0);

        foreach ($rows as $row) {
            $monthly[(int) $row['m']] = (int) $row['c'];
        }

        return $monthly;
    }

    /**
     * Payable contracts per partner, for the leaderboard.
     *
     * @param list<string> $payableStatuses
     *
     * @return list<array<string, mixed>>
     */
    public function payableByPartner(UserScope $scope, array $payableStatuses, int $limit = 5000): array
    {
        if ($payableStatuses === []) {
            return [];
        }

        [$clause, $params] = $this->scopeClause($scope);
        $statuses          = implode(',', array_fill(0, count($payableStatuses), '%s'));

        return $this->rows(
            "SELECT partner_user_id, provider_id, program_id, energy_type, category, status,
                    payout_amount
             FROM %i WHERE status IN ({$statuses}){$clause} LIMIT " . max(1, $limit),
            [Tables::name(Tables::CONTRACTS), ...$payableStatuses, ...$params]
        );
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $params): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    private function scopeClause(UserScope $scope, string $alias = ''): array
    {
        if ($scope->isAdministrator()) {
            return ['', []];
        }

        $column = ($alias === '' ? '' : $alias . '.') . 'partner_user_id';

        return [
            ' AND ' . $column . ' IN (' . $scope->placeholders() . ')',
            $scope->userIds(),
        ];
    }
}
