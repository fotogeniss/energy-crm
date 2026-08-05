<?php

/**
 * Builds the contract PDF outside the request that asked for it.
 *
 * It used to run inside POST /contracts, on every save, drafts included. That
 * one call raises the memory limit to 256 MB, allows itself 60 seconds, loads
 * the template's background images — nine of them, 3.2 MB, for the Orizon
 * form — and renders the whole document with tFPDF.
 *
 * On one save that is invisible. On forty at once it is ten gigabytes and forty
 * PHP workers held for seconds each, while every other request on the site
 * queues behind them. The agent gains nothing from the wait: the download
 * button renders on demand anyway, and the stored copy is only needed once the
 * customer follows a signing link.
 *
 * So saving schedules, and the two moments that genuinely need the file ask for
 * it directly. A missed cron cannot lose the document either — ensure() builds
 * it on the spot if it is not there yet.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_REST;
use EnergyCRM\Persistence\FileRepository;

final class DocumentQueue
{
    public const HOOK = 'ecrm_build_contract_pdf';

    /** The document kind ECRM_REST::store_contract_pdf() writes. */
    private const KIND = 'contract';

    /**
     * Long enough that a burst of saves spreads over several cron ticks rather
     * than landing on the first one, short enough to be ready before anyone
     * looks for it.
     */
    private const DELAY = 30;

    public function __construct(private readonly FileRepository $files)
    {
    }

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'onScheduled'], 10, 1);
    }

    /**
     * Ask for the document to be built shortly.
     *
     * Re-scheduling the same contract is a no-operation in WordPress, which is
     * what we want: five saves in a row queue one render, not five.
     */
    public static function enqueue(int $contractId): void
    {
        if ($contractId <= 0) {
            return;
        }

        wp_schedule_single_event(time() + self::DELAY, self::HOOK, [$contractId]);
    }

    public function onScheduled(int $contractId): void
    {
        ECRM_REST::store_contract_pdf($contractId);
    }

    /**
     * The document, guaranteed to exist before the caller needs it.
     *
     * Used where a customer is about to be sent to it. This path is one render
     * per customer conversation rather than one per keystroke-save, so paying
     * for it in the request is fine.
     *
     * @return bool False only when the render itself failed.
     */
    public function ensure(int $contractId): bool
    {
        if ($this->exists($contractId)) {
            return true;
        }

        return ECRM_REST::store_contract_pdf($contractId);
    }

    private function exists(int $contractId): bool
    {
        foreach ($this->files->forContract($contractId) as $file) {
            if (($file['doc_kind'] ?? '') !== self::KIND) {
                continue;
            }

            $path = (string) ($file['path'] ?? '');

            // A row without its bytes is worse than no row: it hides the gap.
            if ($path !== '' && file_exists($path)) {
                return true;
            }
        }

        return false;
    }
}
