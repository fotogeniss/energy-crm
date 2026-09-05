<?php

/**
 * Ελεύθερες σημειώσεις ΓΙΑ έναν πελάτη (247, Στάδιο 2).
 *
 * Ιδιο σχήμα με το EventRepository: append-only, κανένα edit/delete εδώ --
 * μια σημείωση που γράφτηκε λάθος διορθώνεται με νέα, όχι επεξεργασία της
 * παλιάς, ώστε να μένει σαφές ποιος είπε τι και πότε.
 *
 * Κανένα scope parameter, ίδιος λόγος με το EventRepository: ο καλών φτάνει
 * εδώ μόνο αφού ο πελάτης έχει ήδη περάσει από σκοπευμένο διάβασμα
 * (CustomerRepository::find()/isReachable()) -- ένα δεύτερο εδώ θα έλεγε
 * ψέματα ότι γίνεται έλεγχος που στην πραγματικότητα δεν ξαναγίνεται.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class CustomerNoteRepository
{
    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::CUSTOMER_NOTES);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCustomer(int $customerId): array
    {
        global $wpdb;

        if ($customerId <= 0) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, customer_id, partner_user_id, body, created_at
                 FROM %i WHERE customer_id = %d ORDER BY created_at DESC',
                $this->table,
                $customerId
            ),
            ARRAY_A
        );

        return $rows;
    }

    /**
     * @return int The new note id, or 0 when the customer id was invalid or
     *             the text was empty after trimming.
     */
    public function create(int $customerId, int $authorId, string $body): int
    {
        global $wpdb;

        $body = trim($body);

        if ($customerId <= 0 || $body === '') {
            return 0;
        }

        $wpdb->insert($this->table, [
            'customer_id'     => $customerId,
            'partner_user_id' => max(0, $authorId),
            'body'            => $body,
        ]);

        return (int) $wpdb->insert_id;
    }
}
