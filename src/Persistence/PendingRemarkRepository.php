<?php

/**
 * (348) Οι παρατηρήσεις αίτησης -- γραμμές του `events`, τύπων
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
     * Η τρέχουσα παρατήρηση κάθε είδους.
     *
     * @return array{provider: ?array{text: string, actor: string, created_at: string},
     *               agent: ?array{text: string, actor: string, created_at: string}}
     */
    public function current(int $contractId): array
    {
        $out = ['provider' => null, 'agent' => null];

        foreach (PendingRemarks::kinds() as $kind) {
            $out[$kind] = $this->latest($contractId, PendingRemarks::eventType($kind));
        }

        return $out;
    }

    /** @return array{text: string, actor: string, created_at: string}|null */
    private function latest(int $contractId, string $type): ?array
    {
        global $wpdb;

        if ($contractId <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT message, user_id, created_at FROM %i
                 WHERE contract_id = %d AND type = %s ORDER BY id DESC LIMIT 1',
                Tables::name(Tables::EVENTS),
                $contractId,
                $type
            ),
            ARRAY_A
        );

        if (! is_array($row)) {
            return null;
        }

        $user = (int) $row['user_id'] > 0 ? get_userdata((int) $row['user_id']) : false;

        return [
            'text'       => (string) $row['message'],
            'actor'      => $user ? (string) $user->display_name : '—',
            'created_at' => (string) $row['created_at'],
        ];
    }
}
