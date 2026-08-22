<?php

/**
 * Ευρετήριο στο `events(to_status, contract_id, created_at)`.
 *
 * Ο πίνακας είχε **μόνο** `KEY contract_id`. Κάθε ερώτημα της μορφής «όλες οι
 * συμβάσεις που πέρασαν ποτέ από αυτή την κατάσταση» τον διάβαζε ολόκληρο —
 * και τέτοιο ερώτημα υπάρχει ήδη σε παραγωγή, στην
 * `AnalyticsRepository::averageDaysToActivation()`:
 *
 *     WHERE to_status = 'active' GROUP BY contract_id
 *
 * Δηλαδή το ευρετήριο δεν εξυπηρετεί μόνο τη νέα κάρτα «γιατί κάθεται»· κάνει
 * γρηγορότερο κάτι που ήδη τρέχει κάθε φορά που ανοίγει η οθόνη αναλυτικών.
 *
 * ## Η σειρά των στηλών δεν είναι τυχαία
 *
 * `to_status` πρώτο, γιατί είναι το ισοδύναμο φίλτρο. `contract_id` δεύτερο και
 * `created_at` τρίτο ώστε το ευρετήριο να **καλύπτει** το ερώτημα: η MySQL
 * βγάζει το `MAX(created_at)` ανά σύμβαση χωρίς να αγγίξει τον πίνακα.
 *
 * Το υπάρχον `KEY contract_id` μένει — εξυπηρετεί το «όλα τα γεγονότα ΑΥΤΗΣ της
 * σύμβασης», που είναι το ιστορικό στην οθόνη λεπτομέρειας και τρέχει πολύ πιο
 * συχνά από τα δύο παραπάνω.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class AddEventStatusIndex implements Migration
{
    private const INDEX = 'to_status_time';

    public function id(): string
    {
        return '0018_add_event_status_index';
    }

    public function description(): string
    {
        return 'Ευρετήριο events(to_status, contract_id, created_at) — χρόνος παραμονής ανά κατάσταση';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::EVENTS);

        if (! $schema->hasTable($table) || $schema->hasIndex($table, self::INDEX)) {
            return;
        }

        // Fixed identifiers, closed list; DDL cannot be parameterised.
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $wpdb->query("ALTER TABLE `{$table}` ADD INDEX `" . self::INDEX . '` (`to_status`, `contract_id`, `created_at`)');
    }
}
