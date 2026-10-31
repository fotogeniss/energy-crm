<?php

/**
 * (344) Οι συμβάσεις που θέλουν «Κλήση ανανέωσης», και η εργασία της.
 *
 * Μία εργασία ανά σύμβαση και ημερομηνία λήξης, σε ΟΠΟΙΑΔΗΠΟΤΕ κατάσταση.
 * Η παλιά ECRM_Tasks::generate_renewal_reminders() έλεγχε μόνο τις ανοιχτές:
 * ο πωλητής πατούσε «Έγινε» και το επόμενο πρωί η ίδια εργασία ξαναβγαινε,
 * κάθε μέρα μέχρι τη λήξη. Αν αλλάξει η λήξη (παράταση), βγαίνει νέα κλήση
 * για τη νέα ημερομηνία· αυτό θέλουμε.
 *
 * Ημερομηνίες από το ρολόι της βάσης (CURDATE()).
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Domain\Contract\RenewalCall;

final class RenewalCallRepository
{
    /** Ανά εκτέλεση· ό,τι περισσέψει βγαίνει την επόμενη μέρα. */
    private const BATCH = 500;

    /**
     * Συμβάσεις που λήγουν μέσα σε $days μέρες και δεν έχουν ακόμα κλήση.
     *
     * @return list<array<string, mixed>>
     */
    public function due(int $days): array
    {
        global $wpdb;

        if ($days <= 0) {
            return [];
        }

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.id, c.code, c.customer_id, c.partner_user_id, c.end_date,
                        c.program_id, c.provider_id, c.energy_type, c.category, c.extra_json,
                        DATEDIFF(c.end_date, CURDATE()) AS days_left,
                        cu.first_name, cu.last_name, cu.company_name
                 FROM %i c
                 LEFT JOIN %i cu ON cu.id = c.customer_id
                 WHERE c.end_date IS NOT NULL
                   AND c.end_date >= CURDATE()
                   AND c.end_date <= CURDATE() + INTERVAL %d DAY
                   AND c.status NOT IN ('cancelled', 'draft')
                   AND c.partner_user_id IS NOT NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM %i t
                        WHERE t.contract_id = c.id AND t.kind = %s AND DATE(t.due_at) = c.end_date
                   )
                 ORDER BY c.end_date ASC
                 LIMIT %d",
                Tables::name(Tables::CONTRACTS),
                Tables::name(Tables::CUSTOMERS),
                $days,
                Tables::name(Tables::TASKS),
                RenewalCall::KIND,
                self::BATCH
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * Η εργασία, στον πωλητή της σύμβασης. Η κατάσταση μένει στο DEFAULT
     * της στήλης ('open').
     *
     * @param string $endDate Y-m-d
     */
    public function create(
        int $contractId,
        ?int $customerId,
        int $owner,
        string $endDate,
        string $title,
        string $note,
        string $priority
    ): int {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::TASKS), [
            'contract_id' => $contractId,
            'customer_id' => $customerId,
            'assigned_to' => $owner,
            'created_by'  => 0,
            'kind'        => RenewalCall::KIND,
            'title'       => $title,
            'note'        => $note,
            'due_at'      => $endDate . ' 10:00:00',
            'priority'    => $priority,
        ]);

        return (int) $wpdb->insert_id;
    }
}
