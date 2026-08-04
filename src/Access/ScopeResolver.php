<?php

/**
 * Builds a UserScope for an actor.
 *
 * Behind an interface deliberately: the current implementation walks the
 * `ecrm_parent` user meta tree, which is an N+1 query per request. Step 3
 * replaces it with a materialized path without any caller changing.
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
