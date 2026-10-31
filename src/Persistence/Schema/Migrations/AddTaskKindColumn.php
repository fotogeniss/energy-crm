<?php

/**
 * (344) Στήλη tasks.kind: τι είδους εργασία έφτιαξε το σύστημα.
 *
 * Μέχρι τώρα η «υπενθύμιση ανανέωσης» σημαδευόταν με note = 'auto_renewal',
 * και η σημείωση αυτή φαινόταν αυτούσια στον πωλητή, κάτω από τον τίτλο της
 * εργασίας. Η σήμανση πάει σε δική της στήλη και η σημείωση μένει για τον
 * άνθρωπο. Οι υπάρχουσες εργασίες μεταφέρονται.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Contract\RenewalCall;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddTaskKindColumn implements Migration
{
    public function id(): string
    {
        return '0054_add_task_kind_column';
    }

    public function description(): string
    {
        return 'Στήλη tasks.kind -- «Κλήση ανανέωσης» χωρίς σήμανση στη σημείωση (344)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $tasks = Tables::name(Tables::TASKS);

        if (! $schema->hasTable($tasks)) {
            return;
        }

        if (! $schema->hasColumn($tasks, 'kind')) {
            // Identifiers are constants; DDL cannot be parameterised.
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query("ALTER TABLE `{$tasks}` ADD COLUMN `kind` VARCHAR(24) NULL AFTER `created_by`");
        }

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET kind = %s, note = NULL WHERE note = 'auto_renewal'",
                $tasks,
                RenewalCall::KIND
            )
        );
    }
}
