<?php

/**
 * Keeps the materialized paths in step with the `ecrm_parent` edges.
 *
 * A denormalised column is only as good as the discipline that maintains it.
 * Rather than trusting every call site to remember, the rebuild hangs off the
 * meta hooks themselves, so any code path that moves a partner — admin screen,
 * REST endpoint, WP-CLI, a future importer — triggers it.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use EnergyCRM\Persistence\NetworkRepository;

final class NetworkSync
{
    public function __construct(private readonly NetworkRepository $network)
    {
    }

    public function register(): void
    {
        add_action('added_user_meta', [$this, 'onMetaChanged'], 10, 3);
        add_action('updated_user_meta', [$this, 'onMetaChanged'], 10, 3);
        add_action('deleted_user_meta', [$this, 'onMetaChanged'], 10, 3);
        add_action('user_register', [$this, 'onUserRegistered'], 10, 1);
    }

    /**
     * @param int|array<int, int> $metaId Unused; signature comes from WordPress.
     */
    public function onMetaChanged($metaId, int $userId, string $metaKey): void
    {
        unset($metaId);

        if ($metaKey !== NetworkRepository::PARENT_META) {
            return;
        }

        $this->network->rebuild($userId);
    }

    public function onUserRegistered(int $userId): void
    {
        $this->network->rebuild($userId);
    }
}
