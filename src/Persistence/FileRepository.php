<?php

/**
 * Document rows and the bytes behind them, deleted together.
 *
 * Deleting a contract used to remove the `files` rows and leave the actual
 * documents — scanned ID cards, utility bills, signatures — on disk with
 * nothing pointing at them. Unreachable through the app, invisible to the GDPR
 * erase screen, and still perfectly readable to anyone with filesystem access.
 *
 * A row and its file are one thing. This class is the only place that knows
 * how to remove them, so no caller can remove half.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class FileRepository
{
    private string $table;

    private string $contractsTable;

    private DocumentStorage $storage;

    public function __construct(
        string $storageDir,
        ?string $table = null,
        ?string $contractsTable = null,
    ) {
        $this->table          = $table ?? Tables::name(Tables::FILES);
        $this->contractsTable = $contractsTable ?? Tables::name(Tables::CONTRACTS);
        $this->storage        = new DocumentStorage($storageDir);
    }

    /**
     * Documents attached to a contract, for the detail view.
     *
     * @return list<array<string, mixed>>
     */
    public function forContract(int $contractId): array
    {
        global $wpdb;

        if ($contractId <= 0) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, doc_kind, filename, mime, attachment_id, path, protected
                 FROM %i WHERE contract_id = %d ORDER BY id',
                $this->table,
                $contractId
            ),
            ARRAY_A
        );

        return $rows;
    }

    /**
     * Έγγραφα κρεμασμένα σε ένα lead — ό,τι έστειλε ο πελάτης από τον σύνδεσμο.
     *
     * Ίδιο σχήμα με την `forContract()`, άλλη στήλη. Χωριστή μέθοδος και όχι
     * παράμετρος στην πρώτη: το «ποια στήλη» δεν είναι επιλογή του καλούντος,
     * είναι δύο διαφορετικές ερωτήσεις που τυχαίνει να μοιράζονται πίνακα.
     *
     * @return list<array<string, mixed>>
     */
    public function forLead(int $leadId): array
    {
        global $wpdb;

        if ($leadId <= 0) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, doc_kind, filename, mime, attachment_id, path, protected
                 FROM %i WHERE lead_id = %d ORDER BY id',
                $this->table,
                $leadId
            ),
            ARRAY_A
        );

        return $rows;
    }

    /**
     * The newest document of a kind whose bytes are actually there.
     *
     * Newest by id, which is insertion order: the signing page inserts a fresh
     * row every time, so a re-signed contract has several and the last one is
     * the drawing that counts. A row whose file is missing is skipped rather
     * than returned — handing back a path that does not exist only moves the
     * failure somewhere less obvious.
     *
     * The same containment check as deletion: a path is only handed out if it
     * resolves inside the protected directory. The caller embeds it in a PDF,
     * so a tampered row would otherwise be a way to read a file off the disk.
     */
    /**
     * Τα αποθηκευμένα έγγραφα μιας αίτησης που έχει νόημα να διαβάσει το AI.
     *
     * Υπάρχει επειδή το /extract δεχόταν ΜΟΝΟ αρχεία που μόλις ανέβηκαν. Όταν
     * τα έγγραφα τα στέλνει ο ίδιος ο πελάτης από τον «σύνδεσμό μου», κανείς
     * δεν τα ξανανεβάζει -- και χωρίς αυτό ο πωλητής θα έβλεπε άδεια πεδία
     * πάνω από φάκελο γεμάτο χαρτιά.
     *
     * Δύο φίλτρα, και τα δύο σκόπιμα:
     *
     * - **Μόνο τα ζητούμενα είδη.** Ο εξαγωγέας είναι γραμμένος για ταυτότητα
     *   και λογαριασμό παρόχου. Το συμπληρωμένο έντυπο ή μια εξουσιοδότηση δεν
     *   προσθέτουν πεδία· προσθέτουν κόστος κλήσης και θόρυβο στο μοντέλο.
     * - **Μόνο διαδρομές μέσα στον προστατευμένο φάκελο.** Η διαδρομή έρχεται
     *   από γραμμή βάσης και ο καλών στέλνει τα bytes σε ΕΞΩΤΕΡΙΚΟ API: χωρίς
     *   τον έλεγχο, μια πειραγμένη γραμμή θα ήταν τρόπος να διαβαστεί
     *   οποιοδήποτε αρχείο του διακομιστή. Ίδιος έλεγχος με το
     *   latestPathOfKind(), για τον ίδιο ακριβώς λόγο.
     *
     * Η εμβέλεια ΔΕΝ ελέγχεται εδώ -- είναι ευθύνη του καλούντος, όπως και στο
     * forContract().
     *
     * @param list<string> $kinds
     * @param list<string> $mimes
     *
     * @return list<array{path: string, mime: string, kind: string}>
     */
    public function extractableForContract(int $contractId, array $kinds, array $mimes): array
    {
        return self::extractableFrom($this->forContract($contractId), $kinds, $mimes);
    }

    /**
     * Τα ίδια, για έγγραφα που ο ΠΕΛΑΤΗΣ έστειλε πριν υπάρξει αίτηση.
     *
     * Ο «σύνδεσμός μου» αποθηκεύει με `lead_id`, όχι `contract_id` — τα δύο
     * είναι ξεχωριστές στήλες του ίδιου πίνακα (`class-ecrm-db.php`). Μέχρι
     * σήμερα ο εξαγωγέας έφτανε στα έγγραφα μόνο μέσω αίτησης, οπότε η
     * ανάγνωση ήταν αναγκαστικά ΜΕΤΑ τη δημιουργία της. Αυτό υπάρχει για να
     * μπορεί να γίνει ΠΡΙΝ: ο πωλητής βλέπει τι βρέθηκε και μετά αποφασίζει.
     *
     * Η εμβέλεια ΔΕΝ ελέγχεται εδώ, όπως και στην αδελφή από πάνω — ο καλών
     * ρωτά πρώτα το `LeadRepository::find()` με το `UserScope` του.
     *
     * @param list<string> $kinds
     * @param list<string> $mimes
     *
     * @return list<array{path: string, mime: string, kind: string}>
     */
    public function extractableForLead(int $leadId, array $kinds, array $mimes): array
    {
        return self::extractableFrom($this->forLead($leadId), $kinds, $mimes);
    }

    /**
     * Το φίλτρο, μία φορά.
     *
     * Ήταν το σώμα της `extractableForContract()`. Βγήκε εδώ όταν
     * προστέθηκε η αδελφή της για τα leads: δεύτερη γραφή του ίδιου φίλτρου
     * θα ήταν δεύτερο σημείο να ξεχαστεί ο έλεγχος `contains()` — που είναι
     * ο έλεγχος ασφαλείας, όχι λεπτομέρεια (δες το docblock από πάνω).
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $kinds
     * @param list<string>               $mimes
     *
     * @return list<array{path: string, mime: string, kind: string}>
     */
    private function extractableFrom(array $rows, array $kinds, array $mimes): array
    {
        $documents = [];

        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');
            $mime = (string) ($row['mime'] ?? '');
            $kind = (string) ($row['doc_kind'] ?? '');

            if ($path === '' || ! in_array($kind, $kinds, true) || ! in_array($mime, $mimes, true)) {
                continue;
            }

            if (! $this->storage->contains($path) || ! is_readable($path)) {
                continue;
            }

            $documents[] = ['path' => $path, 'mime' => $mime, 'kind' => $kind];
        }

        return $documents;
    }

    public function latestPathOfKind(int $contractId, string $kind): ?string
    {
        global $wpdb;

        /** @var list<string> $paths */
        $paths = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT path FROM %i WHERE contract_id = %d AND doc_kind = %s ORDER BY id DESC',
                $this->table,
                $contractId,
                $kind
            )
        );

        foreach ($paths as $path) {
            if ($path !== '' && $this->storage->contains($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Record a stored document against a contract.
     *
     * `protected` is always 1: everything written since the secure directory
     * exists lives there, and the flag is what tells serve() to stream it
     * through the signed endpoint instead of looking for a media attachment.
     *
     * @return int The new file id, or 0 when the insert failed.
     */
    public function attach(int $contractId, string $kind, string $filename, string $mime, string $path): int
    {
        global $wpdb;

        $wpdb->insert($this->table, [
            'contract_id'   => $contractId,
            'attachment_id' => null,
            'doc_kind'      => $kind,
            'filename'      => $filename,
            'mime'          => $mime,
            'path'          => $path,
            'protected'     => 1,
        ]);

        return (int) $wpdb->insert_id;
    }

    /**
     * Replace the single document of a given kind on a contract.
     *
     * Used for the signature: a contract has one, and re-signing must not leave
     * the previous drawing behind — on disk as well as in the row. It did not,
     * until FileRepositoryTest caught it: the old row was deleted but its bytes
     * were not, unlike every other removal path in this class. The bytes go
     * first now, the same as purgeGenerated(), purgeForContracts() and
     * purgeOrphans().
     *
     * @return int The new file id, or 0 when the insert failed.
     */
    public function replaceKind(int $contractId, string $kind, string $filename, string $mime, string $path): int
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, path, attachment_id FROM %i WHERE contract_id = %d AND doc_kind = %s',
                $this->table,
                $contractId,
                $kind
            ),
            ARRAY_A
        );

        $this->deleteBytes($rows);

        $wpdb->delete($this->table, ['contract_id' => $contractId, 'doc_kind' => $kind]);

        return $this->attach($contractId, $kind, $filename, $mime, $path);
    }

    /**
     * Remove the documents a build produced, bytes included.
     *
     * Which kinds count as generated is the caller's to say. Everything the
     * customer or the agent uploaded has its own doc_kind and must survive a
     * rebuild untouched: losing a scanned ID card because somebody pressed
     * save twice would be unrecoverable, since the original was never anywhere
     * else.
     *
     * Two details that are not decoration. The prefix goes through esc_like(),
     * so the underscore in `form_` matches an underscore rather than any
     * character. And the delete names the ids that were read, not the
     * condition they were read by — a second build starting in between would
     * otherwise have its fresh rows deleted by this one.
     *
     * @return int Number of rows removed.
     */
    public function purgeGenerated(int $contractId, string $kind, string $sheetPrefix): int
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, path, attachment_id FROM %i
                 WHERE contract_id = %d AND (doc_kind = %s OR doc_kind LIKE %s)',
                $this->table,
                $contractId,
                $kind,
                $wpdb->esc_like($sheetPrefix) . '%'
            ),
            ARRAY_A
        );

        if ($rows === []) {
            return 0;
        }

        $this->deleteBytes($rows);

        $ids          = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $wpdb->query(
            $wpdb->prepare("DELETE FROM {$this->table} WHERE id IN ({$placeholders})", $ids)
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return count($ids);
    }

    /**
     * Remove every document belonging to the given contracts, bytes included.
     *
     * @param list<int> $contractIds
     *
     * @return int Number of rows removed.
     */
    public function purgeForContracts(array $contractIds): int
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $contractIds))));

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, path, attachment_id FROM {$this->table} WHERE contract_id IN ({$placeholders})",
                $ids
            ),
            ARRAY_A
        );

        $this->deleteBytes($rows);

        $removed = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table} WHERE contract_id IN ({$placeholders})",
                $ids
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $removed === false ? 0 : (int) $removed;
    }

    /**
     * Remove documents whose contract no longer exists.
     *
     * These are the leftovers from every deletion made before this class
     * existed. Also worth running periodically: a crash between the row delete
     * and the unlink leaves exactly this shape of debris.
     *
     * @return int Number of rows removed.
     */
    public function purgeOrphans(): int
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            "SELECT f.id, f.path, f.attachment_id
             FROM {$this->table} f
             LEFT JOIN {$this->contractsTable} c ON c.id = f.contract_id
             WHERE f.contract_id IS NOT NULL AND c.id IS NULL",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        if ($rows === []) {
            return 0;
        }

        $this->deleteBytes($rows);

        $ids          = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        $wpdb->query(
            $wpdb->prepare("DELETE FROM {$this->table} WHERE id IN ({$placeholders})", $ids)
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return count($ids);
    }

    /**
     * Τι κρατάει ο δίσκος για αυτές τις συμβάσεις, χωρίς να σβήσει τίποτα.
     *
     * Διαβάζεται ΠΡΙΝ διαγραφεί η σύμβαση. Το foreign key `files.contract_id`
     * είναι ON DELETE CASCADE, οπότε τη στιγμή που φεύγει η σύμβαση φεύγουν και
     * οι γραμμές — και μαζί τους η μόνη ένδειξη ποια αρχεία υπήρχαν. Χωρίς αυτό
     * το στιγμιότυπο, τα σαρωμένα δελτία ταυτότητας μένουν στον δίσκο χωρίς
     * τίποτα να τα δείχνει.
     *
     * @param list<int> $contractIds
     *
     * @return list<array<string, mixed>>
     */
    public function recordsForContracts(array $contractIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $contractIds))));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, path, attachment_id FROM {$this->table} WHERE contract_id IN ({$placeholders})",
                $ids
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        return $rows;
    }

    /**
     * Σβήνει τα bytes ενός στιγμιότυπου από recordsForContracts().
     *
     * Ασφαλές να τρέξει δύο φορές: το wp_delete_file() σε αρχείο που δεν
     * υπάρχει δεν κάνει τίποτα.
     *
     * @param list<array<string, mixed>> $records
     */
    public function forgetBytes(array $records): void
    {
        $this->deleteBytes($records);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function deleteBytes(array $rows): void
    {
        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');

            if ($path !== '' && $this->storage->contains($path)) {
                wp_delete_file($path);
            }

            // Legacy documents that still live in the media library.
            $attachmentId = (int) ($row['attachment_id'] ?? 0);

            if ($attachmentId > 0) {
                wp_delete_attachment($attachmentId, true);
            }
        }
    }
}
