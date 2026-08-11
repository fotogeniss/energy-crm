<?php

/**
 * In-app notifications, scoped to their recipient.
 *
 * A notification belongs to exactly one user, so the scope here is simply that
 * user id — there is no team view of someone else's bell.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class NotificationRepository
{
    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::NOTIFICATIONS);
    }

    /**
     * Write one notice for one recipient.
     *
     * The write side this class was missing: it could read the bell, count it
     * and clear it, but the only thing that filled it was a raw insert in
     * ECRM_REST.
     *
     * A user id of zero is dropped rather than written. The column is NOT NULL,
     * so a row for user zero is one nobody can ever read and nobody can ever
     * mark read — it would sit in the table forever, counted by nothing.
     *
     * A contract id of zero is stored as NULL, because a notice is allowed to
     * be about nothing in particular and a foreign key to contract zero is not.
     */
    public function add(
        int $userId,
        string $type,
        string $title,
        string $body = '',
        int $contractId = 0
    ): void {
        global $wpdb;

        if ($userId <= 0) {
            return;
        }

        $wpdb->insert($this->table, [
            'user_id'     => $userId,
            'contract_id' => $contractId > 0 ? $contractId : null,
            'type'        => $type,
            'title'       => $title,
            'body'        => $body,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentFor(int $userId, int $limit = 30): array
    {
        global $wpdb;

        if ($userId <= 0) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, contract_id, type, title, body, read_at, created_at
                 FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d',
                $this->table,
                $userId,
                max(1, $limit)
            ),
            ARRAY_A
        );

        return $rows;
    }

    public function unreadCount(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE user_id = %d AND read_at IS NULL',
                $this->table,
                $userId
            )
        );
    }

    /**
     * Mark one notification read, or all of them when no id is given.
     *
     * The user id is part of the WHERE clause rather than checked beforehand,
     * so marking someone else's notification read is not expressible.
     */
    public function markRead(int $userId, ?int $id = null): void
    {
        global $wpdb;

        if ($userId <= 0) {
            return;
        }

        $now = current_time('mysql', true);

        if ($id !== null && $id > 0) {
            $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET read_at = %s
                     WHERE user_id = %d AND id = %d AND read_at IS NULL',
                    $this->table,
                    $now,
                    $userId,
                    $id
                )
            );

            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET read_at = %s WHERE user_id = %d AND read_at IS NULL',
                $this->table,
                $now,
                $userId
            )
        );
    }
}
