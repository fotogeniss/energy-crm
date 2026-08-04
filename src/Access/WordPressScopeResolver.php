<?php

/**
 * ScopeResolver backed by WordPress users and capabilities.
 *
 * The downline walk is currently delegated to the legacy ECRM_DB helper so this
 * step changes no behaviour. It is memoised per request, which already removes
 * the repeated tree walks a single request used to trigger.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use ECRM_DB;

final class WordPressScopeResolver implements ScopeResolver
{
    /** @var array<int, UserScope> */
    private array $memo = [];

    public function forCurrentUser(): UserScope
    {
        $userId = get_current_user_id();

        if ($userId <= 0) {
            throw NotAuthenticated::noCurrentUser();
        }

        return $this->forUser($userId);
    }

    public function forUser(int $userId): UserScope
    {
        if ($userId <= 0) {
            throw NotAuthenticated::noCurrentUser();
        }

        return $this->memo[$userId] ??= $this->resolve($userId);
    }

    private function resolve(int $userId): UserScope
    {
        if (user_can($userId, 'manage_options')) {
            return UserScope::forAdministrator($userId);
        }

        if (! user_can($userId, 'ecrm_manage_team')) {
            return UserScope::forSelf($userId);
        }

        /** @var list<int> $downline */
        $downline = array_map('intval', ECRM_DB::visible_user_ids($userId));

        return UserScope::forTeam($userId, $downline);
    }
}
