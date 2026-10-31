<?php

/**
 * (342) Στήλη events.reason: ο κωδικός λόγου απόρριψης/ακύρωσης
 * (Domain\Contract\CloseReason) στο status_change που τον έφερε.
 *
 * Στο γεγονός και όχι στη σύμβαση: μια αίτηση μπορεί να απορριφθεί, να
 * ξαναμπεί και να απορριφθεί για άλλο λόγο -- και τα δύο μετράνε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddEventReasonColumn implements Migration
{
    public function id(): string
    {
        return '0053_add_event_reason_column';
    }

    public function description(): string
    {
        return 'Στήλη events.reason -- λόγος απόρριψης/ακύρωσης (342)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $events = Tables::name(Tables::EVENTS);

        if (! $schema->hasTable($events) || $schema->hasColumn($events, 'reason')) {
            return;
        }

        // Identifiers are constants; DDL cannot be parameterised.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("ALTER TABLE `{$events}` ADD COLUMN `reason` VARCHAR(32) NULL AFTER `message`");
    }
}
