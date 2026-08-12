<?php

/**
 * The rows behind a status change, and the sweep that finds what is due.
 *
 * Three methods, none of which takes a UserScope, and that is the property they
 * were grouped by. The policy that admits them — and the test any future
 * addition has to pass before it is let in — is in ARCHITECTURE.md under
 * «Αναγνώσεις χωρίς actor».
 *
 * In short, for these three: the transition is reached through
 * Domain\Contract\ContractLifecycle, whose callers have already resolved the
 * contract through a scoped read, and adding a second check here would not make
 * it safer — it would make the caller believe the check lives here. The sweep
 * runs from cron, on behalf of nobody, which is the whole point of it existing.
 *
 * Having them in a class named for that property is the guard. The argument used
 * to be a comment in the middle of a 930-line file, and a comment protects a
 * rule only for as long as people read it; a class answers "does this belong
 * here?" by which file you had to open.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class ContractTransitions
{
    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::CONTRACTS);
    }

    /** The status a contract is in right now; '' when there is no such row. */
    public function statusOf(int $contractId): string
    {
        global $wpdb;

        $status = $wpdb->get_var(
            $wpdb->prepare('SELECT status FROM %i WHERE id = %d', $this->table, $contractId)
        );

        return $status === null ? '' : (string) $status;
    }

    /**
     * Write the new status, and whatever columns come with it.
     *
     * `updated_at` is set here rather than left to the caller, because a status
     * change that does not touch it is a change nobody can find afterwards.
     *
     * The extra columns pass through the writable filter, which the old inline
     * version did not do: they are internal today (`signed_at`, `signed_ip`),
     * but "internal" is a property of the callers, and callers change. That
     * filter is now WritableColumns, shared with the save path rather than
     * copied — two lists that agree today are one column away from disagreeing.
     *
     * @param array<string, mixed> $extraColumns
     */
    public function applyTransition(int $contractId, string $status, array $extraColumns = []): void
    {
        global $wpdb;

        if ($contractId <= 0) {
            return;
        }

        $wpdb->update(
            $this->table,
            ['status' => $status, 'updated_at' => current_time('mysql')]
                + WritableColumns::filter($extraColumns),
            ['id' => $contractId]
        );
    }

    /**
     * Contracts still sitting in `signed` whose signature is older than the cutoff.
     *
     * The cutoff is site-local time, because `signed_at` is written with
     * current_time('mysql'). Comparing against UTC would quietly do nothing for
     * as many hours as the site is offset by.
     *
     * @return list<int>
     */
    public function idsSignedBefore(string $cutoffLocalTime, int $onlyId = 0, int $limit = 200): array
    {
        global $wpdb;

        $onlyClause = $onlyId > 0 ? ' AND id = %d' : '';
        $params     = $onlyId > 0
            ? [$this->table, $cutoffLocalTime, $onlyId, $limit]
            : [$this->table, $cutoffLocalTime, $limit];

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<string> $ids */
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM %i
                  WHERE status = 'signed' AND signed_at IS NOT NULL AND signed_at <= %s{$onlyClause}
                  LIMIT %d",
                $params
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return array_values(array_map('intval', $ids));
    }
}
