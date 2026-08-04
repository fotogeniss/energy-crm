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
