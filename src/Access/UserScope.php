<?php

/**
 * Who a given actor is allowed to see and touch.
 *
 * Every repository read and write takes one of these. That is the whole point:
 * you cannot express a contracts query in this codebase without first stating
 * on whose behalf it runs, so "I forgot the ownership check" stops being a
 * thing a developer can accidentally do.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use InvalidArgumentException;

final class UserScope
{
    /** @var non-empty-list<int> */
    private array $userIds;

    /**
     * @param non-empty-list<int> $userIds Partner user ids reachable by the actor.
     */
    private function __construct(
        private readonly int $actorId,
        array $userIds,
        private readonly bool $isAdministrator,
    ) {
        if ($actorId <= 0) {
            throw new InvalidArgumentException('A scope needs a real user id.');
        }

        // Normalise: unique, positive, re-indexed, actor always included.
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id > 0
        )));

        if (! in_array($actorId, $ids, true)) {
            $ids[] = $actorId;
        }

        $this->userIds = $ids;
    }

    /** The actor sees only their own records. */
    public static function forSelf(int $actorId): self
    {
        return new self($actorId, [$actorId], false);
    }

    /**
     * The actor sees their own records plus their downline.
     *
     * @param list<int> $downlineIds
     */
    public static function forTeam(int $actorId, array $downlineIds): self
    {
        return new self($actorId, [$actorId, ...$downlineIds], false);
    }

    /**
     * Unrestricted. Reserved for `manage_options`; still carries an actor id so
     * that audit trails record who acted.
     */
    public static function forAdministrator(int $actorId): self
    {
        return new self($actorId, [$actorId], true);
    }

    public function actorId(): int
    {
        return $this->actorId;
    }

    public function isAdministrator(): bool
    {
        return $this->isAdministrator;
    }

    /** @return non-empty-list<int> */
    public function userIds(): array
    {
        return $this->userIds;
    }

    /** True when the scope covers more than the actor themselves. */
    public function isTeamWide(): bool
    {
        return count($this->userIds) > 1;
    }

    public function includes(int $userId): bool
    {
        return $this->isAdministrator || in_array($userId, $this->userIds, true);
    }

    /**
     * Placeholder list for a `partner_user_id IN (...)` clause, e.g. "%d,%d,%d".
     * Always non-empty, so the caller can never build `IN ()`.
     */
    public function placeholders(): string
    {
        return implode(',', array_fill(0, count($this->userIds), '%d'));
    }

    /** Narrow an existing scope down to the actor alone. */
    public function toSelfOnly(): self
    {
        return self::forSelf($this->actorId);
    }
}
