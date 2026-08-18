<?php

/**
 * ScopeResolver backed by WordPress users and capabilities.
 *
 * The downline comes from NetworkRepository, which answers it with one prefix
 * query against the materialized path. Results are also memoised per request,
 * so a page that asks about the same actor repeatedly pays once.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use EnergyCRM\Persistence\NetworkRepository;

final class WordPressScopeResolver implements ScopeResolver
{
    /** @var array<int, UserScope> */
    private array $memo = [];

    public function __construct(private readonly NetworkRepository $network)
    {
    }

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

    /**
     * @return list<int>
     */
    public function visibleUserIds(UserScope $scope): array
    {
        // Ο διαχειριστής δεν έχει «ομάδα»: έχει την εταιρεία. Η ιεραρχία
        // περιγράφει ποιος κερδίζει προμήθεια, όχι ποιος επιτρέπεται να δει.
        return $scope->isAdministrator()
            ? $this->network->allUserIds()
            : $scope->userIds();
    }

    private function resolve(int $userId): UserScope
    {
        if (user_can($userId, 'manage_options')) {
            return UserScope::forAdministrator($userId);
        }

        if (! user_can($userId, 'ecrm_manage_team')) {
            return UserScope::forSelf($userId);
        }

        return UserScope::forTeam($userId, $this->network->subtreeIds($userId));
    }
}
