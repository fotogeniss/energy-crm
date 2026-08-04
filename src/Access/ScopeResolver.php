<?php

/**
 * Builds a UserScope for an actor.
 *
 * Behind an interface deliberately: swapping the tree walk for a materialized
 * path changed only the implementation, and no caller had to move. The next
 * change — caching, or a dedicated hierarchy table — can happen the same way.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

interface ScopeResolver
{
    /**
     * @throws NotAuthenticated When there is no usable actor.
     */
    public function forUser(int $userId): UserScope;

    /**
     * Scope for whoever is driving the current request.
     *
     * @throws NotAuthenticated When nobody is logged in.
     */
    public function forCurrentUser(): UserScope;
}
