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
        [$clause, $params] = $this->scopeClause($scope, 'c');
        [$kept, $keptArg]  = $this->notDiscarded('c');

        $rows = $this->rows(
            "SELECT c.status, COUNT(*) c FROM %i c WHERE 1 = 1{$clause}{$kept} GROUP BY c.status",
            [Tables::name(Tables::CONTRACTS), ...$params, ...$keptArg]
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
        [$sent, $sentArg]   = $this->submitted('ct');

        return $this->rows(
            "SELECT p.name, MAX(p.logo_url) logo, COUNT(*) c
             FROM %i ct LEFT JOIN %i p ON p.id = ct.provider_id
             WHERE 1 = 1{$clause}{$since}{$sent}
             GROUP BY ct.provider_id ORDER BY c DESC LIMIT " . max(1, $limit),
            [
                Tables::name(Tables::CONTRACTS),
                Tables::name(Tables::PROVIDERS),
                ...$params,
                ...$sinceArg,
                ...$sentArg,
            ]
        );
    }

    /**
     * (342) Απορρίψεις και ακυρώσεις των τελευταίων N ημερών, ανά λόγο και πάροχο.
     *
     * Μετριέται το ΓΕΓΟΝΟΣ (status_change με to_status απόρριψη/ακύρωση), όχι
     * η τωρινή κατάσταση: μια αίτηση που απορρίφθηκε και ξαναμπήκε μετράει
     * ακόμα, γιατί ο λόγος που απορρίφθηκε συνέβη. `reason` κενό = χωρίς λόγο
     * (πριν το 342, εισαγωγή Excel).
     *
     * @param list<string> $statuses
     * @return list<array<string, mixed>> reason, provider, c
     */
    public function lostReasons(UserScope $scope, array $statuses, int $days = 90): array
    {
        if ($statuses === []) {
            return [];
        }

        [$clause, $params] = $this->scopeClause($scope, 'ct');
        $in = implode(',', array_fill(0, count($statuses), '%s'));

        return $this->rows(
            "SELECT COALESCE(ev.reason, '') AS reason, COALESCE(p.name, '') AS provider, COUNT(*) c
             FROM %i ev
             JOIN %i ct ON ct.id = ev.contract_id
             LEFT JOIN %i p ON p.id = ct.provider_id
             WHERE ev.type = 'status_change' AND ev.to_status IN ({$in})
               AND ev.created_at >= NOW() - INTERVAL %d DAY{$clause}
             GROUP BY reason, provider ORDER BY c DESC",
            [
                Tables::name(Tables::EVENTS),
                Tables::name(Tables::CONTRACTS),
                Tables::name(Tables::PROVIDERS),
                ...$statuses,
                max(1, $days),
                ...$params,
            ]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byEnergyType(UserScope $scope): array
    {
        [$clause, $params] = $this->scopeClause($scope, 'c');
        [$sent, $sentArg]  = $this->submitted('c');

        return $this->rows(
            "SELECT c.energy_type, COUNT(*) c FROM %i c WHERE 1 = 1{$clause}{$sent}
             GROUP BY c.energy_type ORDER BY c DESC",
            [Tables::name(Tables::CONTRACTS), ...$params, ...$sentArg]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function topRegions(UserScope $scope, int $limit = 8): array
    {
        [$clause, $params] = $this->scopeClause($scope, 'ct');
        [$sent, $sentArg]  = $this->submitted('ct');

        return $this->rows(
            "SELECT COALESCE(NULLIF(cu.region, ''), '—') name, COUNT(*) c
             FROM %i ct LEFT JOIN %i cu ON cu.id = ct.customer_id
             WHERE 1 = 1{$clause}{$sent}
             GROUP BY name ORDER BY c DESC LIMIT " . max(1, $limit),
            [Tables::name(Tables::CONTRACTS), Tables::name(Tables::CUSTOMERS), ...$params, ...$sentArg]
        );
    }

    /**
     * @return array<int, int>
     */
    public function monthlyTotals(UserScope $scope, int $year): array
    {
        [$clause, $params] = $this->scopeClause($scope, 'c');
        [$sent, $sentArg]  = $this->submitted('c');

        $rows = $this->rows(
            "SELECT MONTH(c.created_at) m, COUNT(*) c FROM %i c
             WHERE YEAR(c.created_at) = %d{$clause}{$sent} GROUP BY MONTH(c.created_at)",
            [Tables::name(Tables::CONTRACTS), $year, ...$params, ...$sentArg]
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

        [$clause, $params]       = $this->scopeClause($scope, 'c');
        [$earned, $earnedParams] = PayableClause::earned($payableStatuses, 'c');

        // (404) Όσες έγιναν ΠΟΤΕ Ενεργές, όπως οι Προμήθειες και οι Εκκαθαρίσεις.
        return $this->rows(
            "SELECT c.partner_user_id, c.provider_id, c.program_id, c.energy_type, c.category, c.status,
                    c.payout_amount
             FROM %i c WHERE {$earned}{$clause} LIMIT " . max(1, $limit),
            [Tables::name(Tables::CONTRACTS), ...$earnedParams, ...$params]
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
        [$clause, $params]  = $this->scopeClause($scope, 'c');
        [$since, $sinceArg] = $this->sinceClause(max(1, $days), 'c');
        [$sent, $sentArg]   = $this->submitted('c');

        $rows = $this->rows(
            "SELECT DATE(c.created_at) d, COUNT(*) c FROM %i c
             WHERE 1 = 1{$clause}{$since}{$sent}
             GROUP BY DATE(c.created_at) ORDER BY d ASC",
            [Tables::name(Tables::CONTRACTS), ...$params, ...$sinceArg, ...$sentArg]
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
        [$clause, $params]  = $this->scopeClause($scope, 'c');
        [$since, $sinceArg] = $this->sinceClause(max(1, $days), 'c');
        [$kept, $keptArg]   = $this->notDiscarded('c');

        $rows = $this->rows(
            "SELECT c.status, COUNT(*) c FROM %i c WHERE 1 = 1{$clause}{$since}{$kept} GROUP BY c.status",
            [Tables::name(Tables::CONTRACTS), ...$params, ...$sinceArg, ...$keptArg]
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
     * (404) Μόνο αιτήσεις που ΣΤΑΛΘΗΚΑΝ: όχι πρόχειρα, και όχι πρόχειρα που
     * πετάχτηκαν (ακυρώθηκαν χωρίς να περάσουν ποτέ από άλλη κατάσταση).
     * Πριν, τα Στατιστικά μετρούσαν και αυτά σε παρόχους, είδη, περιοχές,
     * μήνες και ημέρες, ενώ η Αρχική και το Κατάστημα όχι (403).
     *
     * @return array{0: string, 1: list<string>}
     */
    private function submitted(string $alias): array
    {
        [$kept, $args] = $this->notDiscarded($alias);

        return [" AND {$alias}.status <> 'draft'" . $kept, $args];
    }

    /**
     * (404) Χωρίς τα πρόχειρα που πετάχτηκαν. Ένα πρόχειρο που ακυρώνεται
     * γίνεται «Ακυρώθηκε» χωρίς λόγο· στο funnel φούσκωνε το ποσοστό
     * ακυρώσεων με αιτήσεις που δεν στάλθηκαν ποτέ.
     *
     * «Πετάχτηκε» = ακυρωμένη με γεγονός πρόχειρο → ακύρωση. Μια αίτηση που
     * στάλθηκε και ακυρώθηκε αργότερα δεν έχει τέτοιο γεγονός και μετρά·
     * το ίδιο και οι παλιές χωρίς ιστορικό (εισαγωγές), για να μη χαθούν.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function notDiscarded(string $alias): array
    {
        return [
            " AND NOT ({$alias}.status = 'cancelled' AND EXISTS (SELECT 1 FROM %i sd"
            . " WHERE sd.contract_id = {$alias}.id AND sd.from_status = 'draft' AND sd.to_status = 'cancelled'))",
            [Tables::name(Tables::EVENTS)],
        ];
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
