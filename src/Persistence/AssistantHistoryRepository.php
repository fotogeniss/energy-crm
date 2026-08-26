<?php

/**
 * Το ιστορικό της Λίτσα, ίδιο ένας χρήστης τη φορά -- καμία έννοια ομάδας εδώ.
 *
 * ## Γιατί υπάρχει (build queue 14)
 *
 * Ως τις 26/08/2026 η συνομιλία με τη Λίτσα ζούσε ΜΟΝΟ σε localStorage του
 * browser, σε καθαρό κείμενο (`ecrm-litsa.js`, `KEY = 'ecrm_litsa_history_v1'`).
 * Ένας χρήστης που ρωτούσε τη Λίτσα κάτι για συγκεκριμένο πελάτη άφηνε το
 * όνομα εκείνου του πελάτη αποθηκευμένο επ' αόριστον στη συσκευή -- έξω από
 * τη βάση, έξω από κάθε δικαίωμα πρόσβασης της εφαρμογής, χωρίς λήξη.
 * `PersonalDataCoverageTest` ελέγχει ήδη ότι κάθε πίνακας που κρατά ΠΔΠ
 * περνάει από κρυπτογράφηση/pseudonymisation -- το localStorage ήταν σημείο
 * που τον προσπερνούσε εντελώς.
 *
 * ## Το ίδιο όριο, απλώς στη βάση
 *
 * Ο ιδιοκτήτης επιβεβαίωσε ρητά να μείνει το ίδιο όριο διατήρησης --
 * `self::KEEP = 40` γραμμές ανά χρήστη, ίδιο με το παλιό `history.slice(-40)`.
 * Το `prune()` το επιβάλλει μετά από κάθε εγγραφή, όχι με cron.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class AssistantHistoryRepository
{
    private const KEEP = 40;

    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::ASSISTANT_MESSAGES);
    }

    /**
     * Παλαιότερη πρώτη -- ίδια σειρά με αυτή που ήδη περίμενε το render() του
     * ecrm-litsa.js από το localStorage.
     *
     * @return list<array{role: string, content: string, created_at: string}>
     */
    public function recentFor(int $userId): array
    {
        global $wpdb;

        if ($userId <= 0) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT role, content, created_at FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d',
                $this->table,
                $userId,
                self::KEEP
            ),
            ARRAY_A
        ) ?: [];

        return array_reverse(array_map(
            static fn (array $row): array => [
                'role'       => (string) $row['role'],
                'content'    => (string) $row['content'],
                'created_at' => (string) $row['created_at'],
            ],
            $rows
        ));
    }

    /** Μία γραμμή, μετά αμέσως κλάδεμα στο ίδιο όριο. Κενό περιεχόμενο δεν γράφεται. */
    public function append(int $userId, string $role, string $content): void
    {
        global $wpdb;

        if ($userId <= 0 || trim($content) === '') {
            return;
        }

        $wpdb->insert($this->table, [
            'user_id' => $userId,
            'role'    => $role === 'assistant' ? 'assistant' : 'user',
            'content' => $content,
        ]);

        $this->prune($userId);
    }

    public function clear(int $userId): void
    {
        global $wpdb;

        if ($userId <= 0) {
            return;
        }

        $wpdb->delete($this->table, ['user_id' => $userId]);
    }

    /**
     * Βρίσκει το id της γραμμής ακριβώς στο όριο (OFFSET, όχι υπο-ερώτημα
     * μέσα στο DELETE -- το phpcs.PreparedSQL δεν μπορεί να επαληθεύσει ένθετα
     * %i, βλ. σημείωση στην κορυφή του αρχείου) και σβήνει ό,τι είναι πιο παλιό.
     */
    private function prune(int $userId): void
    {
        global $wpdb;

        $cutoff = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT 1 OFFSET %d',
                $this->table,
                $userId,
                self::KEEP
            )
        );

        if ($cutoff === null) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE user_id = %d AND id <= %d',
                $this->table,
                $userId,
                (int) $cutoff
            )
        );
    }
}
