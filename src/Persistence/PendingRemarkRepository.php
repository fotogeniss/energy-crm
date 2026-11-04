<?php

/**
 * (348) Οι παρατηρήσεις αίτησης -- γραμμές του `events`, τύπων
 * `remark_provider` / `remark_agent`. Η τρέχουσα είναι η τελευταία γραμμή που
 * δεν έχει διαγραφεί. Διαγραφή = νέα γραμμή `remark_removed` με το id της
 * παρατήρησης στο `reason`· καμία γραμμή δεν σβήνεται.
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
     * Η παρατήρηση με αυτό το id, αν ανήκει στην αίτηση και δεν έχει διαγραφεί.
     *
     * @return array{id: int, type: string, user_id: int}|null
     */
    public function find(int $contractId, int $eventId): ?array
    {
        global $wpdb;

        if ($contractId <= 0 || $eventId <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, type, user_id FROM %i WHERE id = %d AND contract_id = %d AND type IN (%s, %s)',
                Tables::name(Tables::EVENTS),
                $eventId,
                $contractId,
                PendingRemarks::EVENT_PROVIDER,
                PendingRemarks::EVENT_AGENT
            ),
            ARRAY_A
        );

        if (! is_array($row) || in_array($eventId, $this->removedIds($contractId), true)) {
            return null;
        }

        return ['id' => (int) $row['id'], 'type' => (string) $row['type'], 'user_id' => (int) $row['user_id']];
    }

    public function remove(int $contractId, int $userId, int $eventId): void
    {
        $this->events->record($contractId, $userId, PendingRemarks::EVENT_REMOVED, [
            'reason' => (string) $eventId,
        ]);
    }

    /** @return list<int> τα id των παρατηρήσεων της αίτησης που διαγράφηκαν */
    public function removedIds(int $contractId): array
    {
        global $wpdb;

        if ($contractId <= 0) {
            return [];
        }

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT reason FROM %i WHERE contract_id = %d AND type = %s',
                Tables::name(Tables::EVENTS),
                $contractId,
                PendingRemarks::EVENT_REMOVED
            )
        );

        return array_values(array_map('intval', (array) $ids));
    }

    /**
     * Η τρέχουσα παρατήρηση κάθε είδους.
     *
     * @return array{provider: ?array{id: int, text: string, actor: string, created_at: string},
     *               agent: ?array{id: int, text: string, actor: string, created_at: string}}
     */
    public function current(int $contractId): array
    {
        $out     = ['provider' => null, 'agent' => null];
        $removed = $this->removedIds($contractId);

        foreach (PendingRemarks::kinds() as $kind) {
            $out[$kind] = $this->latest($contractId, PendingRemarks::eventType($kind), $removed);
        }

        return $out;
    }

    /**
     * @param list<int> $removed
     *
     * @return array{id: int, text: string, actor: string, created_at: string}|null
     */
    private function latest(int $contractId, string $type, array $removed): ?array
    {
        global $wpdb;

        if ($contractId <= 0) {
            return null;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, message, user_id, created_at FROM %i
                 WHERE contract_id = %d AND type = %s ORDER BY id DESC LIMIT 50',
                Tables::name(Tables::EVENTS),
                $contractId,
                $type
            ),
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            if (in_array((int) $row['id'], $removed, true)) {
                continue;
            }

            $user = (int) $row['user_id'] > 0 ? get_userdata((int) $row['user_id']) : false;

            return [
                'id'         => (int) $row['id'],
                'text'       => (string) $row['message'],
                'actor'      => $user ? (string) $user->display_name : '—',
                'created_at' => (string) $row['created_at'],
            ];
        }

        return null;
    }
}
