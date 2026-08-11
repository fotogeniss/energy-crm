<?php

/**
 * The contract's documents: built from the contract, kept beside it.
 *
 * One application is not always one sheet. Electricity and gas are a single
 * provider form; a mobile application is the contract plus whatever the
 * customer's choices added — a porting request, one of the two combined-offer
 * forms. All of them are stored together, because handing the provider the
 * first sheet alone gets the application rejected.
 *
 * Lifted out of ECRM_REST in roadmap step 10c. It was never REST's business:
 * of its four callers, one is a cron job and one is the page an
 * unauthenticated customer signs on.
 *
 * ## Not a pure move
 *
 * The old method read the contract with its own copy of findDetailed()'s
 * query, and the copy was missing the line that closes the original —
 * `fromStorage()` on the customer's columns and on the extras bag. With
 * ECRM_ENCRYPT_PII on, the *stored* form therefore printed `ecrm1:…` where the
 * ΑΦΜ belongs, while the download button, reading through findDetailed(),
 * printed it correctly. Reading through the repository is both the fix and the
 * reason the class is here rather than in Persistence: it no longer knows how
 * to talk to the database at all.
 *
 * The second defect, found the same way: the old method rendered without a
 * signature path, so the copy rebuilt immediately after the customer signed
 * carried no signature — while the download route, which passes one, did. The
 * signature is fetched here and handed to the renderer, once, for every sheet.
 *
 * ## What this class does not do
 *
 * It does not render — SheetRenderer does, and it is an interface so that the
 * question "was it handed the signature?" has an answer that is not a
 * compressed PDF stream. It does not decide *when* to build either:
 * DocumentQueue does, because building on every save held a PHP worker for
 * seconds at 256 MB. And it does not authorise: it takes a bare contract id,
 * and the two REST callers resolve the contract through a scoped read first.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_Files;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\FileRepository;

final class ContractDocuments
{
    /** The kind the application's first sheet is stored under. */
    public const KIND = 'contract';

    /** And every sheet that travels with it. */
    public const SHEET_PREFIX = 'form_';

    /**
     * `files.doc_kind` is VARCHAR(24). A longer template key would be
     * truncated by the database rather than by us — silently on a server
     * without strict mode, which is how two different sheets end up sharing a
     * kind and deleting each other on the next rebuild.
     */
    private const KIND_LENGTH = 24;

    /** The drawing the customer left, which every sheet has to carry. */
    private const SIGNATURE_KIND = 'signature';

    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly FileRepository $files,
        private readonly SheetRenderer $renderer,
    ) {
    }

    /**
     * Build the application and store it against the contract, replacing
     * whatever a previous build left.
     *
     * Best-effort by design: every caller treats a missing document as
     * something to try again later, and none of them can do anything useful
     * with an exception. A signature must not fail because a PDF did.
     *
     * @return bool True when at least the first sheet was stored.
     */
    public function store(int $contractId): bool
    {
        $contract = $this->contracts->detailedForDocument($contractId);

        if ($contract === null) {
            return false;
        }

        $sheets = $this->renderer->render(
            $contract,
            $this->files->latestPathOfKind($contractId, self::SIGNATURE_KIND)
        );

        if ($sheets === []) {
            return false;
        }

        $name = (string) ($contract['code'] ?? '');
        $name = $name !== '' ? $name : 'symvasi-' . $contractId;

        // Only once there is something to put in its place: a rebuild that
        // fails half way through must not have deleted the copy that worked.
        $this->files->purgeGenerated($contractId, self::KIND, self::SHEET_PREFIX);

        $first = array_shift($sheets);

        if (! $this->keep($contractId, self::KIND, $name . '.pdf', $first['bytes'])) {
            return false;
        }

        foreach ($sheets as $sheet) {
            $this->keep(
                $contractId,
                substr(self::SHEET_PREFIX . $sheet['key'], 0, self::KIND_LENGTH),
                $name . '-' . $sheet['key'] . '.pdf',
                $sheet['bytes']
            );
        }

        return true;
    }

    /** Write one sheet to protected storage and record it. */
    private function keep(int $contractId, string $kind, string $filename, string $bytes): bool
    {
        $saved = ECRM_Files::put_bytes($bytes, 'pdf', 'application/pdf', $filename);

        if ($saved === null) {
            return false;
        }

        return $this->files->attach(
            $contractId,
            $kind,
            (string) $saved['filename'],
            (string) $saved['mime'],
            (string) $saved['path']
        ) > 0;
    }
}
