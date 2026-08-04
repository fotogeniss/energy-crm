<?php

/**
 * A partner's position in the network, encoded as a materialized path.
 *
 * "/1/7/23/" means user 23 reports to 7, who reports to 1. Encoding ancestry
 * in the row itself turns "everyone below user 7" from a recursive walk into a
 * single prefix match: WHERE path LIKE '/1/7/%'.
 *
 * The delimiters at both ends matter. Without the trailing slash, '/1/7/'
 * would prefix-match '/1/70/' and a manager would silently see a stranger's
 * customers.
 *
 * Pure string logic, no WordPress: the rules that decide who sees what are
 * worth testing without a database.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use InvalidArgumentException;

final class NetworkPath
{
    private const SEPARATOR = '/';

    private function __construct()
    {
    }

    /** Path of a partner with nobody above them. */
    public static function root(int $userId): string
    {
        self::assertUserId($userId);

        return self::SEPARATOR . $userId . self::SEPARATOR;
    }

    /** Path of a partner reporting to the holder of $parentPath. */
    public static function child(string $parentPath, int $userId): string
    {
        self::assertUserId($userId);

        if (! self::isValid($parentPath)) {
            throw new InvalidArgumentException('Μη έγκυρο parent path: ' . self::sanitise($parentPath));
        }

        return $parentPath . $userId . self::SEPARATOR;
    }

    /**
     * LIKE pattern matching the holder of $path and everyone beneath them.
     *
     * '/1/7/' yields '/1/7/%', which matches '/1/7/' itself as well, since the
     * wildcard also stands for the empty string.
     */
    public static function subtreePattern(string $path): string
    {
        if (! self::isValid($path)) {
            throw new InvalidArgumentException('Μη έγκυρο path: ' . self::sanitise($path));
        }

        return $path . '%';
    }

    /**
     * Every user id along the path, ancestors first, subject last.
     *
     * @return list<int>
     */
    public static function ids(string $path): array
    {
        if (! self::isValid($path)) {
            return [];
        }

        return array_map('intval', array_values(array_filter(explode(self::SEPARATOR, $path), 'strlen')));
    }

    /** The id the path belongs to, or 0 when the path is unusable. */
    public static function subjectId(string $path): int
    {
        $ids = self::ids($path);

        return $ids === [] ? 0 : $ids[count($ids) - 1];
    }

    /** True when $userId appears anywhere along the path. */
    public static function contains(string $path, int $userId): bool
    {
        return in_array($userId, self::ids($path), true);
    }

    /**
     * Structurally sound: slash-delimited positive integers, no duplicates.
     * A repeated id means the parent chain loops back on itself.
     */
    public static function isValid(string $path): bool
    {
        if (preg_match('#^(/\d+)+/$#', $path) !== 1) {
            return false;
        }

        $ids = self::ids($path);

        foreach ($ids as $id) {
            if ($id <= 0) {
                return false;
            }
        }

        return count($ids) === count(array_unique($ids));
    }

    private static function assertUserId(int $userId): void
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Το path χρειάζεται θετικό user id.');
        }
    }

    private static function sanitise(string $value): string
    {
        return (string) preg_replace('#[^0-9/]#', '', $value);
    }
}
