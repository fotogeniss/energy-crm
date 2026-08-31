<?php

/**
 * Reads and maintains the partner network.
 *
 * `ecrm_parent` stays the source of truth for a single edge; `ecrm_path` is the
 * derived materialized path that makes subtree questions answerable in one
 * query. Whenever a parent changes, the path of that user and of everyone
 * beneath them is rewritten by prefix substitution — again, one statement, no
 * per-node walk.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\NetworkPath;

final class NetworkRepository
{
    public const PARENT_META = 'ecrm_parent';
    public const PATH_META   = 'ecrm_path';

    /** Depth guard for a parent chain that loops or is absurdly deep. */
    private const MAX_DEPTH = 50;

    /**
     * The acting user plus everyone below them, in a single query.
     *
     * @return non-empty-list<int>
     */
    public function subtreeIds(int $userId): array
    {
        global $wpdb;

        if ($userId <= 0) {
            return [$userId];
        }

        $path = $this->pathFor($userId);

        /** @var list<string> $ids */
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
                self::PATH_META,
                $wpdb->esc_like($path) . '%'
            )
        );

        $result = array_values(array_unique(array_map('intval', $ids)));

        if (! in_array($userId, $result, true)) {
            $result[] = $userId;
        }

        return $result;
    }

    /**
     * The managers above a user, nearest first, the user themselves excluded.
     *
     * Moved here 31/08 from `ContractNotices::uplineOf()`, which had this
     * exact code private to itself -- the escalation path in
     * `ECRM_Notifications::escalations()` needed the identical walk and
     * duplicating five lines of path arithmetic across two files is exactly
     * the kind of drift `computePath()`'s cycle guard exists to prevent
     * elsewhere. `ContractNotices` now calls this instead of its own copy.
     *
     * The stored path runs the other way round from the answer this method
     * owes its caller: "/1/7/23/" is root first and *includes* 23. Hence the
     * two adjustments, both of which matter --
     *
     *   - `array_slice(..., 0, -1)` drops the subject. Without it the owner
     *     is told twice about their own contract, once as owner and once as
     *     their own manager.
     *   - `array_reverse` restores nearest-first, so callers can walk "who do
     *     I tell first" in the natural order.
     *
     * A user id of zero has no path -- `NetworkPath::root()` rejects it --
     * so it is turned away here.
     *
     * @return list<int>
     */
    public function uplineOf(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $lineage = NetworkPath::ids($this->pathFor($userId));

        return array_reverse(array_slice($lineage, 0, -1));
    }

    /**
     * Every user id on the site, for actors whose visibility is unrestricted.
     *
     * @return list<int>
     */
    public function allUserIds(): array
    {
        global $wpdb;

        /** @var list<string> $ids */
        $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->users}");

        return array_map('intval', $ids);
    }

    /**
     * Stored path for a user, computed and persisted on first use.
     */
    public function pathFor(int $userId): string
    {
        $stored = (string) get_user_meta($userId, self::PATH_META, true);

        if (NetworkPath::isValid($stored) && NetworkPath::subjectId($stored) === $userId) {
            return $stored;
        }

        return $this->rebuild($userId);
    }

    /**
     * Recompute a user's path from the parent chain and move their subtree with
     * them. Returns the new path.
     */
    public function rebuild(int $userId): string
    {
        $old = (string) get_user_meta($userId, self::PATH_META, true);
        $new = $this->computePath($userId);

        update_user_meta($userId, self::PATH_META, $new);

        if ($old !== '' && $old !== $new && NetworkPath::isValid($old)) {
            $this->rewriteSubtree($old, $new);
        }

        return $new;
    }

    /**
     * Rebuild every user's path. Used on upgrade and after bulk imports.
     *
     * @return int Number of users touched.
     */
    public function rebuildAll(): int
    {
        $touched = 0;

        foreach ($this->allUserIds() as $userId) {
            update_user_meta($userId, self::PATH_META, $this->computePath($userId));
            $touched++;
        }

        return $touched;
    }

    /**
     * Walk up the `ecrm_parent` chain and assemble the path.
     *
     * Stops on a repeated id, so a cycle introduced by hand in the database
     * degrades to a shorter path instead of hanging the request.
     */
    private function computePath(int $userId): string
    {
        $chain = [$userId];
        $current = $userId;

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $parent = (int) get_user_meta($current, self::PARENT_META, true);

            if ($parent <= 0 || in_array($parent, $chain, true)) {
                break;
            }

            array_unshift($chain, $parent);
            $current = $parent;
        }

        $path = NetworkPath::root((int) array_shift($chain));

        foreach ($chain as $id) {
            $path = NetworkPath::child($path, $id);
        }

        return $path;
    }

    /**
     * Move a whole subtree by rewriting the common prefix of its paths.
     */
    private function rewriteSubtree(string $oldPath, string $newPath): void
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->usermeta}
                 SET meta_value = CONCAT(%s, SUBSTRING(meta_value, %d))
                 WHERE meta_key = %s AND meta_value LIKE %s",
                $newPath,
                strlen($oldPath) + 1,
                self::PATH_META,
                $wpdb->esc_like($oldPath) . '%'
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    }
}
