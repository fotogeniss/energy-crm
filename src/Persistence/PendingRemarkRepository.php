<?php

/**
 * (348) Οι παρατηρήσεις εκκρεμότητας -- γραμμές του `events`, τύπων
 * `remark_provider` / `remark_agent`. Η τρέχουσα είναι η τελευταία γραμμή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Domain\Contract\PendingRemarks;

final class PendingRemarkRepository
{
    public function __construct(private readonly EventRepository $events = new EventRepository())
    {
    }

    public function add(int $contractId, int $userId, string $kind, string $text): void
    {
        $this->events->record($contractId, $userId, PendingRemarks::eventType($kind), [
            'message' => PendingRemarks::clean($text),
        ]);
    }

    /**
     * Η τρέχουσα παρατήρηση κάθε είδους, ανά αίτηση.
     *
     * @param list<int> $contractIds
     *
     * @return array<int, array{provider: ?array<string, mixed>, agent: ?array<string, mixed>}>
     */
    public function currentFor(array $contractIds): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $contractIds), static fn (int $i): bool => $i > 0));
        $out = [];

        foreach ($ids as $id) {
            $out[$id] = ['provider' => null, 'agent' => null];
        }

        if ($ids === []) {
            return $out;
        }

        $table = Tables::name(Tables::EVENTS);
        $ph    = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.contract_id, e.type, e.message, e.user_id, e.created_at
                 FROM %i e
                 JOIN ( SELECT MAX(id) AS id FROM %i
                        WHERE type IN (%s, %s) AND contract_id IN ($ph)
                        GROUP BY contract_id, type ) last ON last.id = e.id",
                array_merge(
                    [$table, $table, PendingRemarks::EVENT_PROVIDER, PendingRemarks::EVENT_AGENT],
                    $ids
                )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $names = [];
        $uids  = array_values(array_unique(array_filter(
            array_map(static fn (array $r): int => (int) $r['user_id'], (array) $rows)
        )));

        $users = $uids === [] ? [] : get_users(['include' => $uids, 'fields' => ['ID', 'display_name']]);

        foreach ($users as $u) {
            $names[(int) $u->ID] = (string) $u->display_name;
        }

        foreach ((array) $rows as $r) {
            $kind = PendingRemarks::kindOf((string) $r['type']);

            $out[(int) $r['contract_id']][$kind] = [
                'text'       => (string) $r['message'],
                'actor'      => $names[(int) $r['user_id']] ?? '—',
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $out;
    }
}
