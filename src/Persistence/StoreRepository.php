<?php

/**
 * (343) Τα νούμερα της καρτέλας «Κατάστημα» στην Αρχική, ανά πωλητή.
 *
 * Όλες οι ημερομηνίες βγαίνουν από το ρολόι της βάσης (CURDATE(), NOW()),
 * το ίδιο που γράφει τα created_at -- βλ. TimeIsReadInOnePlaceTest. Το scope
 * μπαίνει με το ScopeClause, όπως σε κάθε άλλη λίστα: ο υπεύθυνος βλέπει την
 * ομάδα του, ο διαχειριστής όλη την εταιρεία.
 *
 * Τα πρόχειρα δεν μετράνε ως αιτήσεις: δεν στάλθηκαν ποτέ.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;

final class StoreRepository
{
    /** «Περιμένουν υπογραφή πάνω από 2 μέρες». */
    public const SIGNATURE_DAYS = 2;

    /** Το παράθυρο των απορρίψεων. */
    public const LOST_DAYS = 30;

    /** Αρχή του τρέχοντος μήνα, σε ώρα βάσης. Χωρίς %, για να μη μπερδέψει το prepare(). */
    private const MONTH_START = 'CURDATE() - INTERVAL (DAYOFMONTH(CURDATE()) - 1) DAY';

    /**
     * Αιτήσεις και τελευταία κίνηση ανά πωλητή, με κλειδί το user id.
     *
     * `before` είναι το ίδιο διάστημα του προηγούμενου μήνα: από την 1η του
     * έως σήμερα πριν από έναν μήνα.
     *
     * @return array<int, array{today: int, yesterday: int, month: int, before: int,
     *     awaiting: int, awaiting_late: int, last_activity: string, idle_seconds: int|null}>
     */
    public function contractStats(UserScope $scope): array
    {
        global $wpdb;

        [$clause, $params] = ScopeClause::forScope($scope, 'c');
        $start             = self::MONTH_START;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.partner_user_id uid,
                        SUM( c.status <> 'draft' AND c.created_at >= CURDATE() ) today,
                        SUM( c.status <> 'draft' AND c.created_at >= CURDATE() - INTERVAL 1 DAY
                             AND c.created_at < CURDATE() ) yesterday,
                        SUM( c.status <> 'draft' AND c.created_at >= {$start} ) month,
                        SUM( c.status <> 'draft' AND c.created_at >= ({$start}) - INTERVAL 1 MONTH
                             AND c.created_at < NOW() - INTERVAL 1 MONTH ) before_span,
                        SUM( c.status = 'awaiting_signature' ) awaiting,
                        SUM( c.status = 'awaiting_signature' AND DATEDIFF( NOW(), COALESCE(
                             ( SELECT MAX(e.created_at) FROM %i e
                                WHERE e.contract_id = c.id AND e.to_status = 'awaiting_signature' ),
                             c.updated_at ) ) > %d ) awaiting_late,
                        MAX( c.updated_at ) last_activity,
                        TIMESTAMPDIFF( SECOND, MAX(c.updated_at), NOW() ) idle_seconds
                 FROM %i c
                 WHERE c.partner_user_id IS NOT NULL{$clause}
                 GROUP BY c.partner_user_id",
                [
                    Tables::name(Tables::EVENTS),
                    self::SIGNATURE_DAYS,
                    Tables::name(Tables::CONTRACTS),
                    ...$params,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $out = [];

        foreach ((array) $rows as $row) {
            $out[(int) $row['uid']] = [
                'today'         => (int) $row['today'],
                'yesterday'     => (int) $row['yesterday'],
                'month'         => (int) $row['month'],
                'before'        => (int) $row['before_span'],
                'awaiting'      => (int) $row['awaiting'],
                'awaiting_late' => (int) $row['awaiting_late'],
                'last_activity' => (string) ($row['last_activity'] ?? ''),
                'idle_seconds'  => $row['idle_seconds'] === null ? null : (int) $row['idle_seconds'],
            ];
        }

        return $out;
    }

    /**
     * Πόσες αιτήσεις κάθε πωλητή έγιναν Ενεργές αυτόν τον μήνα.
     *
     * Από το ιστορικό, όχι από την τρέχουσα κατάσταση: μια αίτηση που έγινε
     * ενεργή στις 3 του μήνα και ακυρώθηκε στις 20 ενεργοποιήθηκε πάντως.
     *
     * @return array<int, int>
     */
    public function activatedThisMonth(UserScope $scope): array
    {
        $start = self::MONTH_START;

        return $this->eventCounts(
            $scope,
            "e.to_status = 'active' AND e.created_at >= {$start}",
            []
        );
    }

    /**
     * Απορρίψεις και ακυρώσεις των τελευταίων 30 ημερών ανά πωλητή.
     *
     * Ένα πρόχειρο που πετάχτηκε δεν μετρά -- ίδιος κανόνας με το
     * Domain\Contract\CloseReason::required().
     *
     * @param list<string> $statuses
     *
     * @return array<int, int>
     */
    public function lostByOwner(UserScope $scope, array $statuses): array
    {
        if ($statuses === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($statuses), '%s'));

        return $this->eventCounts(
            $scope,
            "e.to_status IN ({$in}) AND ( e.from_status IS NULL OR e.from_status <> 'draft' )
             AND e.created_at >= NOW() - INTERVAL %d DAY",
            [...$statuses, self::LOST_DAYS]
        );
    }

    /**
     * Οι λόγοι των ίδιων απορρίψεων, ο συχνότερος πρώτος. '' = χωρίς λόγο.
     *
     * @param list<string> $statuses
     *
     * @return list<array{reason: string, count: int}>
     */
    public function lostReasons(UserScope $scope, array $statuses): array
    {
        global $wpdb;

        if ($statuses === []) {
            return [];
        }

        [$clause, $params] = ScopeClause::forScope($scope, 'c');
        $in                = implode(',', array_fill(0, count($statuses), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT COALESCE(e.reason, '') reason, COUNT(DISTINCT e.contract_id) n
                 FROM %i e JOIN %i c ON c.id = e.contract_id
                 WHERE e.type = 'status_change' AND e.to_status IN ({$in})
                   AND ( e.from_status IS NULL OR e.from_status <> 'draft' )
                   AND e.created_at >= NOW() - INTERVAL %d DAY{$clause}
                 GROUP BY reason ORDER BY n DESC, reason ASC",
                [
                    Tables::name(Tables::EVENTS),
                    Tables::name(Tables::CONTRACTS),
                    ...$statuses,
                    self::LOST_DAYS,
                    ...$params,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $out = [];

        foreach ((array) $rows as $row) {
            $out[] = ['reason' => (string) $row['reason'], 'count' => (int) $row['n']];
        }

        return $out;
    }

    /**
     * Αιτήσεις ανά πωλητή που πέρασαν από μια αλλαγή κατάστασης.
     *
     * @param string       $where  SQL με placeholders για τα $values.
     * @param list<scalar> $values
     *
     * @return array<int, int>
     */
    private function eventCounts(UserScope $scope, string $where, array $values): array
    {
        global $wpdb;

        [$clause, $params] = ScopeClause::forScope($scope, 'c');

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.partner_user_id uid, COUNT(DISTINCT e.contract_id) n
                 FROM %i e JOIN %i c ON c.id = e.contract_id
                 WHERE e.type = 'status_change' AND {$where}{$clause}
                 GROUP BY c.partner_user_id",
                [
                    Tables::name(Tables::EVENTS),
                    Tables::name(Tables::CONTRACTS),
                    ...$values,
                    ...$params,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $out = [];

        foreach ((array) $rows as $row) {
            $out[(int) $row['uid']] = (int) $row['n'];
        }

        return $out;
    }
}
