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
