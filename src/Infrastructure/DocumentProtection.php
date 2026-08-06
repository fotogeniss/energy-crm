<?php

/**
 * Drains the backlog of documents that are still publicly reachable.
 *
 * Documents stored before the protected directory existed went into the
 * WordPress media library, whose whole purpose is to be served over HTTP. The
 * plugin has had a "Ασφάλιση παλαιών αρχείων" button for a while, and that is
 * the problem: a scanned ID card stays one guessed URL away from a stranger
 * until somebody happens to open the GDPR screen and press it. Nobody presses
 * a button they were never told about, and the exposure does not announce
 * itself.
 *
 * So it stops being a decision. This runs hourly, takes a slice of the backlog
 * each time, and stops mattering once there is nothing left to move — a state
 * the admin screen reports rather than assumes.
 *
 * Hourly rather than daily because this is a live exposure, and in slices
 * because copying files is the kind of work that must never compete with
 * agents saving contracts. See DocumentQueue for the same reasoning applied to
 * PDF rendering.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Persistence\FileRepository;

final class DocumentProtection
{
    public const HOOK = 'ecrm_protect_documents';

    /**
     * Documents moved per cron tick.
     *
     * A copy is a read plus a write of a few megabytes. Twenty-five of them is
     * a second or two of one worker, once an hour — invisible next to the
     * twenty to forty requests the site handles at the same moment.
     */
    private const BATCH = 25;

    public function __construct(private readonly FileRepository $files)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'onScheduledSweep']);

        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * How many documents are still exposed.
     *
     * The admin screen asks so it can say something true instead of offering a
     * button for work that may not exist.
     */
    public function pending(): int
    {
        return $this->files->unprotectedCount();
    }

    /**
     * One slice.
     *
     * @return array{protected:int, missing:int, failed:int, skipped:int}
     */
    public function sweep(int $limit = self::BATCH): array
    {
        return $this->files->protectBatch($limit);
    }

    /**
     * Cron entry point. WordPress discards return values, so anything worth
     * knowing is logged rather than returned.
     */
    public function onScheduledSweep(): void
    {
        $report = $this->sweep();

        if ($report['protected'] > 0) {
            error_log(
                sprintf('[Energy CRM] Ασφαλίστηκαν %d παλαιά έγγραφα.', $report['protected'])
            );
        }

        // These two do not fix themselves, and a silent retry every hour would
        // hide them forever.
        if ($report['missing'] > 0 || $report['failed'] > 0) {
            error_log(
                sprintf(
                    '[Energy CRM] Έγγραφα που χρειάζονται έλεγχο: %d χωρίς αρχείο, %d απέτυχαν.',
                    $report['missing'],
                    $report['failed']
                )
            );
        }
    }
}
