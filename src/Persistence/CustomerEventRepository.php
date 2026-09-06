<?php

/**
 * Ιστορικό αλλαγών στοιχείων πελάτη (247, Στάδιο 3).
 *
 * Ιδιο σχήμα append-only με το CustomerNoteRepository/EventRepository: καμία
 * επεξεργασία ή διαγραφή γραμμής -- ένα ιστορικό που μπορεί να αλλάξει δεν
 * είναι ιστορικό.
 *
 * old_value/new_value κρυπτογραφούνται ΜΟΝΟ όταν το πεδίο ανήκει στο
 * `CustomerFields::encryptedColumns()` -- ίδια στάθμη προστασίας με την ίδια
 * τη στήλη που καταγράφουν, ούτε παραπάνω ούτε λιγότερη. Η επιλογή γίνεται
 * περνώντας κάθε τιμή από το ήδη υπάρχον `CustomerFields::forStorage()`/
 * `fromStorage()` με το όνομα του πεδίου ως κλειδί -- ίδιος μηχανισμός με
 * αυτόν που ήδη κρυπτογραφεί/αποκρυπτογραφεί τη στήλη customers.<field>,
 * όχι δεύτερος, παράλληλος ορισμός του "ποιο πεδίο είναι ευαίσθητο".
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class CustomerEventRepository
{
    private string $table;

    public function __construct(
        ?string $table = null,
        private ?CustomerFields $fields = null,
    ) {
        $this->table = $table ?? Tables::name(Tables::CUSTOMER_EVENTS);
        $this->fields ??= CustomerFields::default();
    }

    /**
     * Καταγράφει μία γραμμή ανά πεδίο που πραγματικά άλλαξε.
     *
     * @param array<string, array{old: string, new: string}> $changes Κλειδί:
     *     όνομα πεδίου. Ο καλών φιλτράρει ήδη τα πεδία που δεν άλλαξαν -- αυτή
     *     η μέθοδος γράφει ό,τι της δοθεί, χωρίς δεύτερο έλεγχο ισότητας.
     */
    public function record(int $customerId, int $actorId, array $changes): void
    {
        global $wpdb;

        if ($customerId <= 0 || $changes === []) {
            return;
        }

        foreach ($changes as $field => $pair) {
            $wpdb->insert($this->table, [
                'customer_id'     => $customerId,
                'partner_user_id' => max(0, $actorId),
                'field'           => $field,
                'old_value'       => $this->encode($field, $pair['old']),
                'new_value'       => $this->encode($field, $pair['new']),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCustomer(int $customerId, int $limit = 50): array
    {
        global $wpdb;

        if ($customerId <= 0) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, customer_id, partner_user_id, field, old_value, new_value, created_at
                 FROM %i WHERE customer_id = %d ORDER BY created_at DESC, id DESC LIMIT %d',
                $this->table,
                $customerId,
                max(1, $limit)
            ),
            ARRAY_A
        );

        return array_map([$this, 'decode'], $rows);
    }

    /**
     * The single most recent change, or null when none exists yet.
     *
     * @return array<string, mixed>|null
     */
    public function latestForCustomer(int $customerId): ?array
    {
        $rows = $this->forCustomer($customerId, 1);

        return $rows[0] ?? null;
    }

    private function encode(string $field, string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (! in_array($field, CustomerFields::encryptedColumns(), true)) {
            return $value;
        }

        $stored = $this->fields->forStorage([$field => $value]);

        return (string) $stored[$field];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        $field = (string) $row['field'];

        if (! in_array($field, CustomerFields::encryptedColumns(), true)) {
            return $row;
        }

        foreach (['old_value', 'new_value'] as $column) {
            if (! is_string($row[$column]) || $row[$column] === '') {
                continue;
            }

            $row[$column] = (string) $this->fields->fromStorage([$field => $row[$column]])[$field];
        }

        return $row;
    }
}
