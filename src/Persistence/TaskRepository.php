<?php

/**
 * Tasks, scoped to who may act on them.
 *
 * A task is reachable when it is assigned to someone in the actor's scope, or
 * when the actor raised it themselves — a seller who creates a reminder for a
 * colleague must still be able to edit it.
 *
 * As with contracts, that condition lives in the WHERE clause of the write,
 * not in a check preceding it. The old code read the row, decided, then wrote:
 * three steps where one suffices, and two chances to drift apart.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;

final class TaskRepository
{
    private const WRITABLE = ['title', 'note', 'due_at', 'priority', 'status', 'done_at'];

    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::TASKS);
    }

    /**
     * @param 'open'|'done'|'today'|'overdue' $filter
     *
     * @return list<array<string, mixed>>
     */
    public function search(UserScope $scope, string $filter = 'open'): array
    {
        global $wpdb;

        $contracts = Tables::name(Tables::CONTRACTS);
        $customers = Tables::name(Tables::CUSTOMERS);

        [$clause, $params] = $this->reachable($scope, 't');
        $params            = [$this->table, $contracts, $customers, ...$params];

        $conditions = [$clause];

        if ($filter === 'done') {
            $conditions[] = "t.status = 'done'";
        } else {
            $conditions[] = "t.status = 'open'";

            if ($filter === 'today') {
                $conditions[] = 'DATE(t.due_at) = %s';
                $params[]     = current_time('Y-m-d');
            } elseif ($filter === 'overdue') {
                $conditions[] = 't.due_at IS NOT NULL AND t.due_at < %s';
                $params[]     = current_time('mysql');
            }
        }

        $where = implode(' AND ', $conditions);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.*, c.code AS contract_code,
                        cu.first_name, cu.last_name, cu.company_name
                 FROM %i t
                 LEFT JOIN %i c  ON c.id  = t.contract_id
                 LEFT JOIN %i cu ON cu.id = t.customer_id
                 WHERE {$where}
                 ORDER BY ( t.due_at IS NULL ), t.due_at ASC, t.id DESC
                 LIMIT 300",
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        // Whether the row is late — decided here, not in the browser.
        //
        // Το ecrm-view-tasks.js διαβάζει `t.overdue` σε τρία σημεία (κόκκινο
        // pill, η λέξη «εκπρόθεσμη», και η επισήμανση της γραμμής) και ΚΑΝΕΙΣ
        // δεν το έστελνε ποτέ: η λέξη «overdue» υπήρχε μόνο ως τιμή φίλτρου.
        // Και τα τρία ήταν νεκρά από τη μέρα που γράφτηκαν. Βρέθηκε στις
        // 2026-08-14 ανοίγοντας την οθόνη — εργασία με λήξη 20/06/2026 καθόταν
        // στη λίστα σαν κανονική.
        //
        // Υπολογίζεται δίπλα στο WHERE που ορίζει το ΙΔΙΟ πράγμα για την
        // καρτέλα «Εκπρόθεσμες», ώστε η καρτέλα και η σήμανση να μη μπορούν να
        // διαφωνήσουν. Παραγωγή του στη JavaScript θα ξανάφερνε ακριβώς αυτό
        // το ρίσκο: το φίλτρο συγκρίνει με current_time('mysql'), δηλαδή ώρα
        // του site, ενώ ο browser θα συνέκρινε με το δικό του ρολόι.
        //
        // Πραγματικό bool, όχι το '1'/'0' string που θα γύριζε υπολογισμένη
        // στήλη SQL μέσω $wpdb: στη JavaScript το string '0' είναι truthy, που
        // θα σήμαινε ΚΑΘΕ εργασία εκπρόθεσμη αντί για καμία.
        $now = current_time('mysql');

        foreach ($rows as $i => $row) {
            $rows[$i]['overdue'] = ($row['status'] ?? '') !== 'done'
                && ! empty($row['due_at'])
                && (string) $row['due_at'] < $now;
        }

        return $rows;
    }

    /**
     * Σημαδεύει «είδε τη λίστα» τις εργασίες που μόλις επιστράφηκαν σε αυτόν
     * τον χρήστη -- ό,τι έχει ήδη seen_at δεν ξαναγράφεται, οπότε η κλήση
     * είναι φθηνή να γίνεται σε κάθε φόρτωση της οθόνης (214, βλ. TasksController).
     *
     * Μόνο IN (), ποτέ κατά scope: ο καλών έχει ήδη φιλτράρει τα ids σε
     * `assigned_to === τον ίδιο` πριν φτάσει εδώ -- το seen_at είναι προσωπικό
     * του καθενός badge, όχι κάτι που μοιράζεται μια ομάδα.
     *
     * @param list<int> $ids
     */
    public function markSeen(array $ids): void
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));

        if ($ids === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET seen_at = %s WHERE id IN ({$placeholders}) AND seen_at IS NULL",
                [$this->table, current_time('mysql'), ...$ids]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): int
    {
        global $wpdb;

        $wpdb->insert($this->table, $data);

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $taskId, UserScope $scope, array $data): bool
    {
        global $wpdb;

        $data = array_intersect_key($data, array_flip(self::WRITABLE));

        if ($taskId <= 0 || $data === []) {
            return false;
        }

        $assignments = [];
        $values      = [];

        foreach ($data as $column => $value) {
            $assignments[] = '`' . $column . '` = ' . ($value === null ? 'NULL' : '%s');

            if ($value !== null) {
                $values[] = $value;
            }
        }

        [$clause, $params] = $this->reachable($scope, '');

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $affected = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET ' . implode(', ', $assignments) . " WHERE id = %d AND {$clause}",
                [$this->table, ...$values, $taskId, ...$params]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $affected !== false && $affected > 0;
    }

    /**
     * Δίνει τις ΑΝΟΙΧΤΕΣ εργασίες ενός ανθρώπου σε άλλον.
     *
     * Μόνο τις ανοιχτές, και η διάκριση είναι το νόημα: μια ολοκληρωμένη
     * εργασία λέει «αυτός ο άνθρωπος έκανε αυτό, τότε» — είναι αρχείο, και
     * μεταφέροντάς την θα ξαναγράφαμε ιστορία. Μια ανοιχτή λέει «κάποιος πρέπει
     * να κάνει αυτό», και όταν ο κάποιος φύγει πρέπει να γίνει άλλος.
     *
     * Χωρίς scope: τρέχει από τη διαγραφή χρήστη, όπου δεν υπάρχει πάντα
     * συνδεδεμένος actor — μια διαγραφή μπορεί να έρθει και από WP-CLI. Την
     * εξουσία την έχει ήδη κρίνει το WordPress, που δεν αφήνει κανέναν χωρίς
     * `delete_users` να φτάσει ως εδώ. Ίδιο σκεπτικό με την
     * `ContractRepository::clearExtractionPayloads()`, που είναι επίσης
     * συντήρηση χωρίς πρόσωπο από πίσω.
     *
     * @return int Πόσες εργασίες μετακινήθηκαν.
     */
    public function handOverOpen(int $fromUserId, int $toUserId): int
    {
        global $wpdb;

        if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId) {
            return 0;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $moved = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET assigned_to = %d WHERE assigned_to = %d AND status = 'open'",
                [$this->table, $toUserId, $fromUserId]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $moved === false ? 0 : (int) $moved;
    }

    public function delete(int $taskId, UserScope $scope): bool
    {
        global $wpdb;

        [$clause, $params] = $this->reachable($scope, '');

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $affected = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE id = %d AND {$clause}",
                [$this->table, $taskId, ...$params]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $affected !== false && $affected > 0;
    }

    /**
     * Condition for "this actor may act on this task", plus its bound values.
     *
     * @return array{0: string, 1: list<int>}
     */
    private function reachable(UserScope $scope, string $alias): array
    {
        $prefix = $alias === '' ? '' : $alias . '.';

        if ($scope->isAdministrator()) {
            return ['1 = 1', []];
        }

        return [
            '(' . $prefix . 'assigned_to IN (' . $scope->placeholders() . ')'
            . ' OR ' . $prefix . 'created_by = %d)',
            [...$scope->userIds(), $scope->actorId()],
        ];
    }
}
