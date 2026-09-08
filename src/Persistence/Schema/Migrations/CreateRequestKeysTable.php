<?php

/**
 * Πίνακας request_keys -- η δέσμευση που κάνει μια δημιουργία να μετρήσει
 * μία φορά (Επίπεδο Γ του `docs/OFFLINE-MODEL.md`).
 *
 * Ξεχωριστός πίνακας και όχι στήλη στο `contracts`, για έναν μετρημένο λόγο:
 * στη ροή του `ContractSaveController::save()` ο **πελάτης** γράφεται πριν τη
 * σύμβαση. Ενα UNIQUE πάνω στη σύμβαση θα έπιανε τη δεύτερη σύμβαση αφού
 * είχε ήδη γραφτεί δεύτερη γραμμή πελάτη. Η δέσμευση πρέπει να μπορεί να
 * γίνει πριν υπάρξει οτιδήποτε -- άρα χρειάζεται δική της γραμμή.
 *
 * `UNIQUE (partner_user_id, request_key)`: το κλειδί ανήκει στον συνεργάτη
 * που το έστειλε. Καθολικό UNIQUE θα έκανε ένα uuid πόρο που ο ένας μπορεί να
 * κλέψει από τον άλλο.
 *
 * `CREATE TABLE` απευθείας με `$wpdb->query()`, όχι `dbDelta()` -- ίδιο
 * σκεπτικό και ίδιο μοτίβο με το `CreateCustomerNotesTable` (0034): το
 * `dbDelta()` θέλει upgrade helpers που δεν είναι πάντα φορτωμένα εδώ. Ο
 * πίνακας μπαίνει ΕΠΙΣΗΣ στο `dbDelta()` του `includes/class-ecrm-db.php`,
 * για τη νέα εγκατάσταση -- και εκεί τον διαβάζει ο
 * `PersonalDataCoverageTest`, που σαρώνει το σχήμα από εκείνο το αρχείο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;
use EnergyCRM\Persistence\Tables;

final class CreateRequestKeysTable implements Migration
{
    public function id(): string
    {
        return '0036_create_request_keys_table';
    }

    public function description(): string
    {
        return 'Νέος πίνακας request_keys -- idempotency στη δημιουργία σύμβασης (Επίπεδο Γ)';
    }

    public function apply(SchemaInspector $schema): void
    {
        global $wpdb;

        $table = Tables::name(Tables::REQUEST_KEYS);

        if ($schema->hasTable($table)) {
            return;
        }

        $charset = $wpdb->get_charset_collate();

        // Ιδιο σχήμα με το dbDelta() του includes/class-ecrm-db.php. Το
        // phpcs:ignore μιας γραμμής δεν αρκεί -- η παράθεση απλώνεται σε πολλές.
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query(
            "CREATE TABLE `{$table}` (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                partner_user_id BIGINT UNSIGNED NOT NULL,
                request_key     CHAR(36) NOT NULL,
                contract_id     BIGINT UNSIGNED NULL,
                created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at    DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY partner_request (partner_user_id, request_key),
                KEY contract_id (contract_id)
            ) {$charset}"
        );
        // phpcs:enable WordPress.DB.PreparedSQL
    }
}
