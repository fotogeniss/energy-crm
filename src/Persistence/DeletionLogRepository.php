<?php

/**
 * Το μόνιμο αρχείο της «ειδικής πύλης» admin.
 *
 * ## Γιατί δεν είναι απλώς μια ακόμα γραμμή στο events
 *
 * Το `events` πεθαίνει μαζί με τη σύμβαση (`ON DELETE CASCADE`, δες
 * `AddForeignKeys`). Η στιγμή που γράφεται εδώ είναι ΑΚΡΙΒΩΣ η στιγμή πριν
 * σβηστεί η σύμβαση που την αφορά -- αν ζούσε στο `events`, θα εξαφανιζόταν
 * μαζί με την ίδια την ενέργεια που έπρεπε να αποδείξει ότι έγινε.
 *
 * ## Γιατί όχι customer_id/contract_id σαν ζωντανή στήλη
 *
 * Δες το σχόλιο πάνω από το `dbDelta` στο `class-ecrm-db.php`. Η
 * `deleted_contract_id` είναι στιγμιότυπο αριθμού, όχι foreign key -- η
 * γραμμή που θα έδειχνε έχει ήδη φύγει τη στιγμή που διαβάζεται.
 *
 * ## Γιατί δεν γράφεται `deleted_at` από εδώ
 *
 * Ίδιο μάθημα με το `EventRepository::record()`: το `deleted_at` το γράφει η
 * MySQL (`DEFAULT CURRENT_TIMESTAMP`), ώρα βάσης. Ένα `current_time('mysql')`
 * εδώ θα ήταν ώρα site -- διαφορετική ζώνη, ίδια παγίδα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class DeletionLogRepository
{
    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::DELETION_LOG);
    }

    /**
     * Γράφεται ΠΡΙΝ φύγει η σύμβαση -- ποτέ μετά, αλλιώς ένα σφάλμα ανάμεσα
     * στα δύο θα άφηνε διαγραφή χωρίς ίχνος.
     */
    public function record(
        int $contractId,
        string $contractCode,
        string $statusAtDeletion,
        string $reason,
        int $deletedBy,
        string $deletedByName
    ): void {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'deleted_contract_id' => $contractId,
                'contract_code'       => $contractCode,
                'status_at_deletion'  => $statusAtDeletion,
                'reason'              => $reason,
                'deleted_by'          => max(0, $deletedBy),
                'deleted_by_name'     => $deletedByName,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );
    }
}
