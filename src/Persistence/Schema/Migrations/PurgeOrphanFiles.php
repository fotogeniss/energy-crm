<?php

/**
 * Removes documents left behind by contracts deleted before FileRepository.
 *
 * Every deletion made up to this point dropped the `files` row and left the
 * document itself on disk. Those files hold identity cards and utility bills,
 * so this is a data-protection fix, not housekeeping.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class PurgeOrphanFiles implements Migration
{
    public function id(): string
    {
        return '0002_purge_orphan_files';
    }

    public function description(): string
    {
        return 'Διαγραφή εγγράφων από συμβάσεις που έχουν ήδη σβηστεί';
    }

    public function apply(SchemaInspector $schema): void
    {
        if (! $schema->hasTable(Tables::name(Tables::FILES))) {
            return;
        }

        $removed = Services::files()->purgeOrphans();

        if ($removed > 0) {
            error_log(sprintf('[Energy CRM] Καθαρίστηκαν %d ορφανά έγγραφα.', $removed));
        }
    }
}
