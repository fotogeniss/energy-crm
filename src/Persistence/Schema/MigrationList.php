<?php

/**
 * Every migration, in the order they must run.
 *
 * Append only. Reordering or renaming an entry that has shipped will make live
 * sites either re-run a change or skip one, because the id is the only record
 * that something already happened.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema;

use EnergyCRM\Persistence\Schema\Migrations\EnsureLegacyColumns;

final class MigrationList
{
    private function __construct()
    {
    }

    /** @return list<Migration> */
    public static function all(): array
    {
        return [
            new EnsureLegacyColumns(),
        ];
    }
}
