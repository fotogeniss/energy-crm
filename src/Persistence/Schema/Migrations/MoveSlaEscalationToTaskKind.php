<?php

/**
 * (372) Οι εργασίες κλιμάκωσης SLA σημαδεύονταν με note = 'sla_escalation',
 * και η λέξη φαινόταν αυτούσια κάτω από τον τίτλο της εργασίας. Η σήμανση
 * πάει στη στήλη tasks.kind (0054), όπως η «Κλήση ανανέωσης», και η σημείωση
 * αδειάζει. Η ώρα δημιουργίας μένει ίδια: με αυτήν το cron ξέρει ότι μια
 * αίτηση έχει ήδη κλιμακωθεί.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Domain\Contract\SlaEscalation;
use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class MoveSlaEscalationToTaskKind implements Migration
{
    public function id(): string
    {
        return '0059_move_sla_escalation_to_task_kind';
    }

    public function description(): string
    {
        return 'Κλιμάκωση SLA: σήμανση στο tasks.kind, όχι στη σημείωση (372)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $tasks = Tables::name(Tables::TASKS);

        if (! $schema->hasTable($tasks) || ! $schema->hasColumn($tasks, 'kind')) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET kind = %s, note = NULL WHERE note = %s',
                $tasks,
                SlaEscalation::KIND,
                SlaEscalation::KIND
            )
        );
    }
}
