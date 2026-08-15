<?php

/**
 * Τα έγγραφα που κάθονται ακόμη εκεί που τα φτάνει ένα URL.
 *
 * Ό,τι αποθηκεύτηκε πριν υπάρξει ο προστατευμένος φάκελος πήγε στη media
 * library του WordPress, που είναι εξ ορισμού αναγνώσιμη από τον κόσμο: μια
 * σαρωμένη ταυτότητα εκεί απέχει ένα μαντεμένο URL από έναν άγνωστο. Αυτή η
 * κλάση τα μετράει και τα μετακομίζει, λίγα-λίγα, από cron.
 *
 * ## Γιατί βγήκε από τον FileRepository (2026-08-15)
 *
 * Ο `FileRepository` υπόσχεται στην επικεφαλίδα του ένα πράγμα: «μια γραμμή
 * και το αρχείο της είναι ένα πράγμα, και κανένας καλών δεν μπορεί να
 * αφαιρέσει το μισό». Αυτά τα μέλη δεν αφαιρούν ΠΟΤΕ γραμμή — **μετακινούν
 * bytes**, με δικό τους ρυθμό cron, δικό τους χειρισμό συνθηκών ανταγωνισμού
 * και δικό τους σχήμα αναφοράς. Ήταν δεύτερη ευθύνη κάτω από την ίδια στέγη.
 *
 * Και έχουν **διαφορετική διάρκεια ζωής**: όταν κάθε site αδειάσει το backlog
 * του, αυτό το αρχείο γίνεται διαγράψιμο ολόκληρο. Ο `FileRepository` όχι.
 * Αυτό είναι το κριτήριο που έκρινε το σπάσιμο — όχι το μέγεθος.
 *
 * *Το `purging` ΔΕΝ βγήκε, σκόπιμα. Θα απαιτούσε το `deleteBytes()` να γίνει
 * κοινό, και τότε η υπόσχεση της επικεφαλίδας θα έπαυε να επιβάλλεται από τη
 * γλώσσα και θα γινόταν κανόνας που πρέπει να θυμάται κάποιος. Ολόκληρο το
 * σκεπτικό στο `ARCHITECTURE.md`, «Μεγέθη κλάσεων».*
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

final class UnprotectedDocuments
{
    private string $table;

    public function __construct(
        private readonly DocumentStorage $storage,
        ?string $table = null,
    ) {
        $this->table = $table ?? Tables::name(Tables::FILES);
    }

    /**
     * Πόσα απομένουν.
     *
     * *Παγίδα του schema, καταγεγραμμένη ώστε να μη ξανακοστίσει: η συνθήκη
     * ρωτά `protected = 0 OR protected IS NULL`, αλλά το `EnsureLegacyColumns`
     * ορίζει τη στήλη `TINYINT NOT NULL DEFAULT 0`. Καμία γραμμή δεν μπορεί να
     * έχει NULL, οπότε το μισό της συνθήκης δεν δοκιμάζεται ποτέ.*
     */
    public function count(): int
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
        if ($path !== '' && $this->storage->contains($path)) {
            return $this->flagProtected($fileId, $path) ? 'protected' : 'skipped';
        }

        $source = $attachmentId > 0 ? (string) get_attached_file($attachmentId) : '';

        if ($source === '' || ! file_exists($source)) {
            return 'missing';
        }

        $destination = $this->storage->newPath((string) pathinfo($source, PATHINFO_EXTENSION));

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
}
