<?php

/**
 * (339) Οι αναφορές προβλημάτων και η συζήτησή τους.
 *
 * Μόνο SQL. Ποιος βλέπει τι αποφασίζει το Domain\Ticket\TicketPolicy και
 * έρχεται εδώ ως λίστα `$reporters` (null = όλοι). Οι ώρες τις γράφει η βάση
 * (NOW()), όπως παντού -- βλ. TimeIsReadInOnePlaceTest.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class TicketRepository
{
    private const LIST_COLUMNS = 't.id, t.user_id, t.status, t.urgent, t.body, t.context, t.created_at, t.updated_at,
        t.closed_at, DATEDIFF(NOW(), t.closed_at) AS days_closed,
        (t.attachment_path IS NOT NULL) AS has_attachment,
        ( SELECT COUNT(*) FROM %i c WHERE c.ticket_id = t.id AND c.kind = \'comment\' ) AS comments';

    /**
     * @param array<string, mixed> $context Καθαρισμένο (TicketContext::clean()).
     */
    public function create(int $userId, string $body, bool $urgent, array $context): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::TICKETS), [
            // Χωρίς 'status': το DEFAULT 'new' του πίνακα (StatusIsWrittenInOnePlaceTest
            // κρατά τη στήλη status των συμβάσεων σε ένα σημείο).
            'user_id' => $userId,
            'urgent'  => $urgent ? 1 : 0,
            'body'    => $body,
            'context' => $context === [] ? null : (string) wp_json_encode($context, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $wpdb->insert_id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- σταθερές στήλες.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT ' . self::LIST_COLUMNS . ', t.attachment_path, t.attachment_mime FROM %i t WHERE t.id = %d',
                Tables::name(Tables::TICKET_COMMENTS),
                Tables::name(Tables::TICKETS),
                $id
            ),
            ARRAY_A
        );
        // phpcs:enable

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<int>|null $reporters null = όλων.
     * @param list<string>   $statuses
     * @return list<array<string, mixed>>
     */
    public function listFor(?array $reporters, array $statuses, int $limit = 200): array
    {
        global $wpdb;

        if ($statuses === [] || $reporters === []) {
            return [];
        }

        [$where, $params] = $this->where($reporters, $statuses);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT ' . self::LIST_COLUMNS . " FROM %i t WHERE {$where}
                 ORDER BY t.urgent DESC, t.created_at DESC, t.id DESC LIMIT %d",
                Tables::name(Tables::TICKET_COMMENTS),
                Tables::name(Tables::TICKETS),
                ...$params,
                ...[max(1, $limit)]
            ),
            ARRAY_A
        );
        // phpcs:enable

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Πόσες ανά κατάσταση.
     *
     * @param list<int>|null $reporters
     * @return array<string, int>
     */
    public function counts(?array $reporters): array
    {
        global $wpdb;

        if ($reporters === []) {
            return [];
        }

        $where  = '1 = 1';
        $params = [];

        if ($reporters !== null) {
            $where  = 'user_id IN (' . implode(',', array_fill(0, count($reporters), '%d')) . ')';
            $params = $reporters;
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS n FROM %i WHERE {$where} GROUP BY status",
                Tables::name(Tables::TICKETS),
                ...$params
            ),
            ARRAY_A
        );
        // phpcs:enable

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['status']] = (int) $row['n'];
        }

        return $out;
    }

    public function addComment(int $ticketId, int $userId, string $body, string $kind = 'comment'): void
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::TICKET_COMMENTS), [
            'ticket_id' => $ticketId,
            'user_id'   => $userId,
            'kind'      => $kind,
            'body'      => $body,
        ]);

        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET updated_at = NOW() WHERE id = %d',
            Tables::name(Tables::TICKETS),
            $ticketId
        ));
    }

    /** @return list<array<string, mixed>> */
    public function comments(int $ticketId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, user_id, kind, body, created_at FROM %i WHERE ticket_id = %d ORDER BY id',
                Tables::name(Tables::TICKET_COMMENTS),
                $ticketId
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    /** Κατάσταση· `closed_at` = NOW() στις κλειστές, NULL στις ανοιχτές. */
    public function setStatus(int $id, string $status, bool $closed): void
    {
        global $wpdb;

        $sql = $closed
            ? 'UPDATE %i SET status = %s, updated_at = NOW(), closed_at = NOW() WHERE id = %d'
            : 'UPDATE %i SET status = %s, updated_at = NOW(), closed_at = NULL WHERE id = %d';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- μία από δύο σταθερές.
        $wpdb->query($wpdb->prepare($sql, Tables::name(Tables::TICKETS), $status, $id));
    }

    public function setAttachment(int $id, string $path, string $mime): void
    {
        global $wpdb;

        $wpdb->update(
            Tables::name(Tables::TICKETS),
            ['attachment_path' => $path, 'attachment_mime' => $mime],
            ['id' => $id]
        );
    }

    /** Αδειάζει τη στήλη· επιστρέφει τη διαδρομή που είχε, για να σβηστεί το αρχείο. */
    public function clearAttachment(int $id): ?string
    {
        global $wpdb;

        $table = Tables::name(Tables::TICKETS);
        $path  = $wpdb->get_var($wpdb->prepare('SELECT attachment_path FROM %i WHERE id = %d', $table, $id));

        if (! is_string($path) || $path === '') {
            return null;
        }

        $wpdb->update($table, ['attachment_path' => null, 'attachment_mime' => null], ['id' => $id]);

        return $path;
    }

    /**
     * Κλειστές πριν από N μήνες: σβήνονται μαζί με τη συζήτησή τους.
     *
     * @return list<string> Διαδρομές εικόνων που έμειναν (δεν θα έπρεπε), για σβήσιμο.
     */
    public function purgeClosedOlderThan(int $months): array
    {
        global $wpdb;

        $tickets  = Tables::name(Tables::TICKETS);
        $comments = Tables::name(Tables::TICKET_COMMENTS);

        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM %i WHERE closed_at IS NOT NULL AND closed_at < NOW() - INTERVAL %d MONTH',
            $tickets,
            max(1, $months)
        )));

        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $paths = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT attachment_path FROM %i WHERE id IN ({$in}) AND attachment_path IS NOT NULL",
            $tickets,
            ...$ids
        ));
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE ticket_id IN ({$in})", $comments, ...$ids));
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE id IN ({$in})", $tickets, ...$ids));
        // phpcs:enable

        return array_values(array_map('strval', $paths));
    }

    /**
     * @param list<int>|null $reporters
     * @param list<string>   $statuses
     * @return array{0: string, 1: list<int|string>}
     */
    private function where(?array $reporters, array $statuses): array
    {
        $where  = 't.status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';
        $params = $statuses;

        if ($reporters !== null) {
            $where .= ' AND t.user_id IN (' . implode(',', array_fill(0, count($reporters), '%d')) . ')';
            $params = [...$params, ...$reporters];
        }

        return [$where, $params];
    }
}
