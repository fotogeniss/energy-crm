<?php

/**
 * (345) Πίνακας targets: στόχος αιτήσεων ανά πωλητή και μήνα.
 *
 * Ανά μήνα και όχι μία τιμή ανά πωλητή: ο Οκτώβριος κρατά τον στόχο του
 * Οκτωβρίου όταν αλλάξει ο Νοέμβριος, και η μηνιαία αναφορά τον διαβάζει.
 * Ίδιος ορισμός στο dbDelta του includes/class-ecrm-db.php.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class CreateTargetsTable implements Migration
{
    public function id(): string
    {
        return '0055_create_targets_table';
    }

    public function description(): string
    {
        return 'Νέος πίνακας targets -- στόχοι αιτήσεων ανά πωλητή και μήνα (345)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $targets = Tables::name(Tables::TARGETS);

        if ($schema->hasTable($targets)) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        // Identifiers are constants; DDL cannot be parameterised.
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query(
            "CREATE TABLE `{$targets}` (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id      BIGINT UNSIGNED NOT NULL,
                month        CHAR(7) NOT NULL,
                applications INT UNSIGNED NOT NULL,
                set_by       BIGINT UNSIGNED NULL,
                updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY user_month (user_id, month),
                KEY month (month)
            ) {$charset}"
        );
        // phpcs:enable WordPress.DB.PreparedSQL
    }
}
