<?php

/**
 * Raised when work is attempted without an identifiable actor.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use RuntimeException;

final class NotAuthenticated extends RuntimeException
{
    public static function noCurrentUser(): self
    {
        return new self('Δεν υπάρχει συνδεδεμένος χρήστης.');
    }
}
