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

    /**
     * Το ένα μισό της παλιάς κλάσης που έφυγε, ακόμη προσβάσιμο από εδώ.
     *
     * Λεπτό περιτύλιγμα κατά τη μετακόμιση: οι δύο δημόσιες μέθοδοι παρακάτω
     * απλώς προωθούν, ώστε κανένας καλών και κανένα test να μη χρειαστεί να
     * αλλάξει στο ίδιο commit με το σπάσιμο. Φεύγει μόλις στραφεί το
     * `DocumentProtection`.
     */
    private UnprotectedDocuments $unprotected;

    public function __construct(
        string $storageDir,
        ?string $table = null,
        ?string $contractsTable = null,
    ) {
        $this->table          = $table ?? Tables::name(Tables::FILES);
        $this->contractsTable = $contractsTable ?? Tables::name(Tables::CONTRACTS);
        $this->storage        = new DocumentStorage($storageDir);
        $this->unprotected    = new UnprotectedDocuments($this->storage, $this->table);
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
     * @deprecated Μετακόμισε στο UnprotectedDocuments::count(). Περιτύλιγμα.
     */
    public function unprotectedCount(): int
    {
        return $this->unprotected->count();
    }

    /**
     * @deprecated Μετακόμισε στο UnprotectedDocuments::protectBatch(). Περιτύλιγμα.
     *
     * @return array{protected:int, missing:int, failed:int, skipped:int}
     */
    public function protectBatch(int $limit = 25): array
    {
        return $this->unprotected->protectBatch($limit);
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
