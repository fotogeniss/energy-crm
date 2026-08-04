<?php

/**
 * Permission callbacks, in one place.
 *
 * Written as small factories so a route reads as a sentence — `Guards::needs(
 * Capability::DELETE_CONTRACT)` — and so the "logged in and holds a CRM role"
 * floor is applied identically everywhere instead of being retyped per route.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\Capability;

final class Guards
{
    private function __construct()
    {
    }

    /** Any authenticated CRM user. */
    public static function crmUser(): callable
    {
        return static fn (): bool =>
            is_user_logged_in() && current_user_can(Capability::USE_CRM);
    }

    /** A CRM user who also holds a specific capability. */
    public static function needs(string $capability): callable
    {
        return static fn (): bool =>
            is_user_logged_in()
            && current_user_can(Capability::USE_CRM)
            && current_user_can($capability);
    }
}
