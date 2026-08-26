<?php

/**
 * Leads, scoped to the partner who owns them.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use EnergyCRM\Access\UserScope;

final class LeadRepository
{
    private const WRITABLE = [
        'name', 'phone', 'email', 'source', 'energy_type', 'stage',
        'callback_at', 'interest', 'notes', 'lost_reason', 'contract_id',
        'updated_at',
    ];

    private string $table;

    public function __construct(?string $table = null)
    {
        $this->table = $table ?? Tables::name(Tables::LEADS);
    }

    /** @return array<string, mixed>|null */
    public function find(int $leadId, UserScope $scope): ?array
    {
        global $wpdb;

        [$clause, $params] = $this->scopeClause($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE id = %d{$clause}",
                [$this->table, $leadId, ...$params]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(UserScope $scope, string $stage = '', string $term = ''): array
    {
        global $wpdb;

        [$clause, $params] = $this->scopeClause($scope);
        $params            = [$this->table, ...$params];
        $conditions        = ['1 = 1' . $clause];

        if ($stage !== '') {
            $conditions[] = 'stage = %s';
            $params[]     = $stage;
        }

        if ($term !== '') {
            $like         = '%' . $wpdb->esc_like($term) . '%';
            $conditions[] = '( name LIKE %s OR phone LIKE %s OR email LIKE %s OR interest LIKE %s )';
            $params       = [...$params, $like, $like, $like, $like];
        }

        $where = implode(' AND ', $conditions);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE {$where}
                 ORDER BY (callback_at IS NULL), callback_at ASC, updated_at DESC
                 LIMIT 500",
                $params
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    public function countsByStage(UserScope $scope): array
    {
        global $wpdb;

        [$clause, $params] = $this->scopeClause($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT stage, COUNT(*) AS total FROM %i WHERE 1 = 1{$clause} GROUP BY stage",
                [$this->table, ...$params]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['stage']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, UserScope $scope): int
    {
        global $wpdb;

        $row = array_intersect_key($data, array_flip(self::WRITABLE));

        // Ownership is assigned here, never taken from the request.
        $row['partner_user_id'] = $scope->actorId();

        $wpdb->insert($this->table, $row);

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $leadId, UserScope $scope, array $data): bool
    {
        global $wpdb;

        $data = array_intersect_key($data, array_flip(self::WRITABLE));

        if ($leadId <= 0 || $data === []) {
            return false;
        }

        $assignments = [];
        $values      = [];

        foreach ($data as $column => $value) {
            $assignments[] = '`' . $column . '` = ' . ($value === null ? 'NULL' : '%s');

            if ($value !== null) {
                $values[] = $value;
            }
        }

        [$clause, $params] = $this->scopeClause($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $affected = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET ' . implode(', ', $assignments) . " WHERE id = %d{$clause}",
                [$this->table, ...$values, $leadId, ...$params]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $affected !== false && ($affected > 0 || $this->find($leadId, $scope) !== null);
    }

    /**
     * Το ΜΟΝΟ σημείο ατομικής απόφασης στη μετατροπή lead → πελάτης/σύμβαση.
     *
     * Εύρημα ελέγχου ασφαλείας/λογικής #4 (26/08/2026): το παλιό
     * `LeadsController::convert()` διάβαζε το lead, έβλεπε `contract_id`
     * κενό, έφτιαχνε πελάτη+σύμβαση, και μόνο στο τέλος ενημέρωνε το lead --
     * χωρίς κανένα σημείο ατομικής απόφασης ανάμεσα στο διάβασμα και τη
     * γραφή, παρά το docblock που υποσχόταν idempotency. Δύο σχεδόν
     * ταυτόχρονες μετατροπές περνούσαν και οι δύο τον έλεγχο και έφτιαχναν
     * δύο ξεχωριστούς πελάτες/συμβάσεις.
     *
     * Πρώτη υλοποίηση δοκίμασε ένα sentinel `0` πριν καν φτιαχτεί η
     * σύμβαση, ώστε να «κλειδώσει» το lead νωρίς -- έσπαγε, γιατί το
     * `leads.contract_id` έχει ΚΙ ΑΥΤΟ foreign key προς `contracts.id`
     * (βλ. `AddForeignKeys::relations()`), και το `0` δεν αντιστοιχεί σε
     * καμία πραγματική σύμβαση. Η σωστή σειρά είναι η αντίστροφη: ο
     * πελάτης/σύμβαση φτιάχνεται πρώτα (ικανοποιεί το FK ούτως ή άλλως),
     * και ΜΟΝΟ το τελευταίο βήμα -- αυτό εδώ -- είναι το ατομικό σημείο
     * απόφασης, με guarded `UPDATE ... WHERE contract_id IS NULL`. Ο
     * χαμένος (`affected === 0`) διαγράφει το πρόχειρο που μόλις έφτιαξε
     * (βλ. `LeadsController::convert()`) -- κανείς δεν πρόλαβε να το δει.
     */
    public function finishConversion(int $leadId, UserScope $scope, int $contractId): bool
    {
        global $wpdb;

        [$clause, $params] = $this->scopeClause($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET contract_id = %d, stage = 'won', updated_at = %s
                 WHERE id = %d AND contract_id IS NULL{$clause}",
                [$this->table, $contractId, current_time('mysql'), $leadId, ...$params]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return (int) $affected > 0;
    }

    /**
     * Δίνει όλα τα leads ενός συνεργάτη σε άλλον.
     *
     * Ζωντανή δουλειά, όχι αρχείο: ένα lead είναι άνθρωπος που περιμένει
     * τηλέφωνο. Όταν φεύγει ο συνεργάτης, ή το αναλαμβάνει κάποιος ή χάνεται.
     *
     * Παράλληλη της `ContractRepository::handOver()`, με τον ίδιο έλεγχο: και
     * οι δύο πλευρές μέσα στο scope αυτού που το ζητά.
     *
     * @return int Πόσα leads μετακινήθηκαν.
     */
    public function handOver(int $fromUserId, int $toUserId, UserScope $scope): int
    {
        global $wpdb;

        if ($fromUserId <= 0 || $toUserId <= 0 || $fromUserId === $toUserId) {
            return 0;
        }

        if (! $scope->includes($fromUserId) || ! $scope->includes($toUserId)) {
            return 0;
        }

        [$clause, $params] = $this->scopeClause($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $moved = $wpdb->query(
            $wpdb->prepare(
                "UPDATE %i SET partner_user_id = %d WHERE partner_user_id = %d{$clause}",
                [$this->table, $toUserId, $fromUserId, ...$params]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $moved === false ? 0 : (int) $moved;
    }

    public function delete(int $leadId, UserScope $scope): bool
    {
        global $wpdb;

        [$clause, $params] = $this->scopeClause($scope);

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $affected = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE id = %d{$clause}",
                [$this->table, $leadId, ...$params]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $affected !== false && $affected > 0;
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    private function scopeClause(UserScope $scope): array
    {
        if ($scope->isAdministrator()) {
            return ['', []];
        }

        return [
            ' AND partner_user_id IN (' . $scope->placeholders() . ')',
            $scope->userIds(),
        ];
    }
}
