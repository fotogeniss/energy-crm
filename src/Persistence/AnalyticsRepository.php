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
    public function averageDaysToActivation(UserScope $scope, int $days = 0): ?float
    {
        global $wpdb;

        [$clause, $params]  = $this->scopeClause($scope, 'c');
        [$since, $sinceArg] = $this->sinceClause($days, 'c');

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $average = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG(DATEDIFF(e.activated, c.created_at))
                 FROM %i c
                 JOIN ( SELECT contract_id, MIN(created_at) activated FROM %i
                        WHERE to_status = 'active' GROUP BY contract_id ) e
                   ON e.contract_id = c.id
                 WHERE 1 = 1{$clause}{$since}",
                [
                    Tables::name(Tables::CONTRACTS),
                    Tables::name(Tables::EVENTS),
                    ...$params,
                    ...$sinceArg,
                ]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $average === null ? null : round((float) $average, 1);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topProviders(UserScope $scope, int $limit = 8, int $days = 0): array
    {
        [$clause, $params]  = $this->scopeClause($scope, 'ct');
        [$since, $sinceArg] = $this->sinceClause($days, 'ct');

        return $this->rows(
            "SELECT p.name, COUNT(*) c
             FROM %i ct LEFT JOIN %i p ON p.id = ct.provider_id
             WHERE 1 = 1{$clause}{$since}
             GROUP BY ct.provider_id ORDER BY c DESC LIMIT " . max(1, $limit),
            [
                Tables::name(Tables::CONTRACTS),
                Tables::name(Tables::PROVIDERS),
                ...$params,
                ...$sinceArg,
            ]
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
     * Νέες αιτήσεις ανά ημέρα, για την τάση της Παρακολούθησης.
     *
     * Επιστρέφει ΜΟΝΟ τις ημέρες που έχουν έστω μία -- η οθόνη γεμίζει τα κενά
     * με μηδέν, γιατί μόνο εκείνη ξέρει πόσο μακριά πίσω κοιτάζει.
     *
     * @return array<string, int> 'YYYY-MM-DD' => πλήθος.
     */
    public function dailyCreated(UserScope $scope, int $days): array
    {
        [$clause, $params]  = $this->scopeClause($scope);
        [$since, $sinceArg] = $this->sinceClause(max(1, $days));

        $rows = $this->rows(
            "SELECT DATE(created_at) d, COUNT(*) c FROM %i
             WHERE 1 = 1{$clause}{$since}
             GROUP BY DATE(created_at) ORDER BY d ASC",
            [Tables::name(Tables::CONTRACTS), ...$params, ...$sinceArg]
        );

        $daily = [];

        foreach ($rows as $row) {
            $daily[(string) $row['d']] = (int) $row['c'];
        }

        return $daily;
    }

    /**
     * Ιδιο σχήμα με το countsByStatus(), αλλά μόνο για όσες δημιουργήθηκαν μέσα
     * στην περίοδο -- ώστε το conversion να λέει «τον τελευταίο μήνα» και όχι
     * «από την αρχή», που δεν κουνιέται ποτέ και άρα δεν προειδοποιεί ποτέ.
     *
     * @return array<string, int>
     */
    public function statusCountsSince(UserScope $scope, int $days): array
    {
        [$clause, $params]  = $this->scopeClause($scope);
        [$since, $sinceArg] = $this->sinceClause(max(1, $days));

        $rows = $this->rows(
            "SELECT status, COUNT(*) c FROM %i WHERE 1 = 1{$clause}{$since} GROUP BY status",
            [Tables::name(Tables::CONTRACTS), ...$params, ...$sinceArg]
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * Συνεργάτες που δεν έχουν καταχωρίσει τίποτα μέσα στην περίοδο.
     *
     * Διαβάζει ΜΟΝΟ τον πίνακα συμβάσεων, οπότε απαντά «ποιος σταμάτησε», όχι
     * «ποιος δεν ξεκίνησε ποτέ»: όποιος δεν έχει ούτε μία αίτηση στη ζωή του
     * δεν εμφανίζεται εδώ. Είναι άλλη ερώτηση (onboarding, όχι πτώση) και
     * θέλει τον πίνακα χρηστών -- σκόπιμα εκτός.
     *
     * @return list<array{partner_user_id: int, last: string}> Παλαιότερη κίνηση πρώτη.
     */
    public function partnersIdle(UserScope $scope, int $days, int $limit = 10): array
    {
        [$clause, $params] = $this->scopeClause($scope);

        $rows = $this->rows(
            "SELECT partner_user_id, MAX(created_at) last FROM %i
             WHERE partner_user_id > 0{$clause}
             GROUP BY partner_user_id
             HAVING MAX(created_at) < DATE_SUB(CURDATE(), INTERVAL %d DAY)
             ORDER BY last ASC LIMIT " . max(1, $limit),
            [Tables::name(Tables::CONTRACTS), ...$params, max(1, $days)]
        );

        return array_map(
            static fn (array $row): array => [
                'partner_user_id' => (int) $row['partner_user_id'],
                'last'            => (string) $row['last'],
            ],
            $rows
        );
    }

    /**
     * Το χρονικό παράθυρο, σε ένα σημείο.
     *
     * Το όριο το υπολογίζει η ΒΑΣΗ (`DATE_SUB(CURDATE(), …)`) και όχι η PHP: η
     * στήλη `created_at` γράφεται από τη βάση, και τα δύο ρολόγια αυτής της
     * εγκατάστασης έχουν ήδη διαφωνήσει κατά τρεις ώρες -- όριο υπολογισμένο
     * με άλλο ρολόι από τη στήλη κόβει λάθος μέρα (CHANGELOG (84)).
     *
     * Μηδέν σημαίνει «χωρίς όριο», ώστε οι υπάρχοντες καλούντες να μην αλλάξουν
     * συμπεριφορά.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function sinceClause(int $days, string $alias = ''): array
    {
        if ($days < 1) {
            return ['', []];
        }

        $column = ($alias === '' ? '' : $alias . '.') . 'created_at';

        return [' AND ' . $column . ' > DATE_SUB(CURDATE(), INTERVAL %d DAY)', [$days]];
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
