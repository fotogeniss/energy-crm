<?php

/**
 * (339) Πίνακες tickets και ticket_comments -- αναφορές προβλημάτων.
 *
 * `CREATE TABLE` απευθείας με `$wpdb->query()`, ίδιο μοτίβο με το
 * `CreateCustomerEventsTable`. Οι ίδιοι πίνακες μπαίνουν και στο `dbDelta()`
 * του `includes/class-ecrm-db.php` για νέες εγκαταστάσεις. Γιατί χωρίς στήλη
 * `contract_id`: δες εκεί.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class CreateTicketTables implements Migration
{
    public function id(): string
    {
        return '0052_create_ticket_tables';
    }

    public function description(): string
    {
        return 'Νέοι πίνακες tickets και ticket_comments -- αναφορές προβλημάτων (339)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $tickets  = Tables::name(Tables::TICKETS);
        $comments = Tables::name(Tables::TICKET_COMMENTS);
        $charset  = $wpdb->get_charset_collate();

        // phpcs:disable WordPress.DB.PreparedSQL
        if (! $schema->hasTable($tickets)) {
            $wpdb->query(
                "CREATE TABLE `{$tickets}` (
                    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id         BIGINT UNSIGNED NOT NULL,
                    status          VARCHAR(20) NOT NULL DEFAULT 'new',
                    urgent          TINYINT(1) NOT NULL DEFAULT 0,
                    body            TEXT NOT NULL,
                    context         TEXT NULL,
                    attachment_path VARCHAR(255) NULL,
                    attachment_mime VARCHAR(40) NULL,
                    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    closed_at       DATETIME NULL,
                    PRIMARY KEY (id),
                    KEY user_id (user_id),
                    KEY status (status)
                ) {$charset}"
            );
        }

        if (! $schema->hasTable($comments)) {
            $wpdb->query(
                "CREATE TABLE `{$comments}` (
                    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    ticket_id  BIGINT UNSIGNED NOT NULL,
                    user_id    BIGINT UNSIGNED NOT NULL,
                    kind       VARCHAR(12) NOT NULL DEFAULT 'comment',
                    body       TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY ticket_id (ticket_id)
                ) {$charset}"
            );
        }
        // phpcs:enable WordPress.DB.PreparedSQL
    }
}
