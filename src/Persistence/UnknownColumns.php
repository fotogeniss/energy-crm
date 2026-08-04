<?php

/**
 * Raised when a caller tries to write a column outside a repository allowlist.
 *
 * The message is assembled here, once, and the offending names are stripped to
 * identifier-safe characters on the way in. Callers therefore never build the
 * message themselves, so no request-controlled string can reach a throw site.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use InvalidArgumentException;

final class UnknownColumns extends InvalidArgumentException
{
    /**
     * @param list<array-key> $columns
     */
    public static function forEntity(string $entity, array $columns): self
    {
        $safe = array_map(
            static fn ($column): string => (string) preg_replace(
                '/[^A-Za-z0-9_]/',
                '',
                (string) $column
            ),
            $columns
        );

        return new self(
            sprintf('Μη εγγράψιμες στήλες (%s): %s', $entity, implode(', ', $safe))
        );
    }
}
