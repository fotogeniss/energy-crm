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

    /** Absolute path to the protected storage directory. */
    private string $storageDir;

    public function __construct(
        string $storageDir,
        ?string $table = null,
        ?string $contractsTable = null,
    ) {
        $this->storageDir     = rtrim($storageDir, '/\\');
        $this->table          = $table ?? Tables::name(Tables::FILES);
        $this->contractsTable = $contractsTable ?? Tables::name(Tables::CONTRACTS);
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
     * the previous drawing behind.
     *
     * @return int The new file id, or 0 when the insert failed.
     */
    public function replaceKind(int $contractId, string $kind, string $filename, string $mime, string $path): int
    {
        global $wpdb;

        $wpdb->delete($this->table, ['contract_id' => $contractId, 'doc_kind' => $kind]);

        return $this->attach($contractId, $kind, $filename, $mime, $path);
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
     * Documents still sitting where a URL can reach them.
     *
     * Anything stored before this class existed went into the WordPress media
     * library, which is world-readable by design: a scanned ID card there is
     * one guessed URL away from a stranger. `protected` marks the ones that
     * have since been moved into storage the web server refuses to serve.
     */
    public function unprotectedCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE protected = 0 OR protected IS NULL',
                $this->table
            )
        );
    }

    /**
     * Move a bounded number of them into protected storage.
     *
     * Bounded because this runs on cron: a site with thousands of legacy
     * documents must not spend one PHP worker copying all of them while agents
     * are trying to save contracts. Each tick takes a slice; the backlog drains
     * over a few hours and the site never notices.
     *
     * @return array{protected:int, missing:int, failed:int, skipped:int}
     *         protected — moved and flagged;
     *         missing   — the row points at bytes that no longer exist;
     *         failed    — the copy did not succeed, retried next tick;
     *         skipped   — another worker got there first.
     */
    public function protectBatch(int $limit = 25): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, attachment_id, path FROM %i
                 WHERE protected = 0 OR protected IS NULL
                 ORDER BY id LIMIT %d',
                $this->table,
                max(1, $limit)
            ),
            ARRAY_A
        );

        $report = ['protected' => 0, 'missing' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($rows as $row) {
            $report[$this->protect($row)]++;
        }

        return $report;
    }

    /**
     * One document, from public to protected.
     *
     * The copy happens before the row is flagged, never after. A crash between
     * the two leaves a spare file on disk and the row still marked unprotected,
     * so the next tick retries it — which is the harmless failure. Flagging
     * first would mean a row that claims to be safe while the public copy is
     * still there, which is the failure that matters.
     *
     * @param array<string, mixed> $row
     *
     * @return 'protected'|'missing'|'failed'|'skipped'
     */
    private function protect(array $row): string
    {
        $fileId       = (int) ($row['id'] ?? 0);
        $path         = (string) ($row['path'] ?? '');
        $attachmentId = (int) ($row['attachment_id'] ?? 0);

        // Already in protected storage and only the flag was missing — which
        // also un-breaks it, because serve() refuses to read an unflagged path.
        if ($path !== '' && $this->isInsideStorage($path)) {
            return $this->flagProtected($fileId, $path) ? 'protected' : 'skipped';
        }

        $source = $attachmentId > 0 ? (string) get_attached_file($attachmentId) : '';

        if ($source === '' || ! file_exists($source)) {
            return 'missing';
        }

        $destination = $this->newStoragePath((string) pathinfo($source, PATHINFO_EXTENSION));

        if (! copy($source, $destination)) {
            return 'failed';
        }

        chmod($destination, 0640);

        if (! $this->flagProtected($fileId, $destination)) {
            // Lost the race; our copy is the redundant one.
            wp_delete_file($destination);

            return 'skipped';
        }

        // Only now is the public copy expendable.
        if ($attachmentId > 0) {
            wp_delete_attachment($attachmentId, true);
        }

        return 'protected';
    }

    /**
     * Point the row at protected storage, if nobody else already did.
     *
     * The `protected = 0` condition is what makes this a claim rather than an
     * overwrite: two overlapping cron runs both reach it, one changes a row and
     * the other changes none.
     */
    private function flagProtected(int $fileId, string $path): bool
    {
        global $wpdb;

        $changed = $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET path = %s, protected = 1, attachment_id = NULL
                 WHERE id = %d AND (protected = 0 OR protected IS NULL)',
                $this->table,
                $path,
                $fileId
            )
        );

        return $changed === 1;
    }

    /** An unguessable name inside the protected directory. */
    private function newStoragePath(string $extension): string
    {
        $safe = (string) preg_replace('/[^a-z0-9]/i', '', $extension);

        return $this->storageDir . DIRECTORY_SEPARATOR
            . 'doc_' . wp_generate_password(24, false) . '.' . ($safe !== '' ? $safe : 'bin');
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function deleteBytes(array $rows): void
    {
        foreach ($rows as $row) {
            $path = (string) ($row['path'] ?? '');

            if ($path !== '' && $this->isInsideStorage($path)) {
                wp_delete_file($path);
            }

            // Legacy documents that still live in the media library.
            $attachmentId = (int) ($row['attachment_id'] ?? 0);

            if ($attachmentId > 0) {
                wp_delete_attachment($attachmentId, true);
            }
        }
    }

    /**
     * Never unlink a path just because a database row said so.
     *
     * The column holds an absolute path, and a tampered or mis-migrated row
     * could point anywhere on the filesystem. Only paths that resolve inside
     * the plugin's own storage directory are touched.
     */
    private function isInsideStorage(string $path): bool
    {
        $resolved = realpath($path);
        $base     = realpath($this->storageDir);

        if ($resolved === false || $base === false) {
            return false;
        }

        return str_starts_with($resolved, $base . DIRECTORY_SEPARATOR);
    }
}
