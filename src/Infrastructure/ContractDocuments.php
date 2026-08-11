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
 * ## What this class does not do
 *
 * It does not decide *when* to build — DocumentQueue does, and it exists
 * because building on every save held a PHP worker for seconds at 256 MB. It
 * does not authorise either: it takes a bare contract id, and the two REST
 * callers resolve the contract through a scoped read before they get here.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_Files;
use ECRM_FormFill;
use ECRM_PDF;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\FileRepository;
use Throwable;

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

    /**
     * The Orizon contract alone loads nine background images, 3.2 MB of them,
     * and renders eleven pages. The defaults on a shared host are not enough
     * and the failure is a blank document rather than an error.
     */
    private const MEMORY = '256M';

    private const SECONDS = 60;

    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly FileRepository $files,
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

        ini_set('memory_limit', self::MEMORY);
        set_time_limit(self::SECONDS);

        $sheets = $this->render($contract);

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

    /**
     * Every sheet of the application, in the order the provider expects them.
     *
     * The provider's own form is preferred — it is what the customer signs and
     * what the provider accepts. The internal summary is the fallback for a
     * provider we have no template for yet, so that a contract always has
     * *something* attached.
     *
     * A sheet that failed to render is dropped rather than fatal: an agent who
     * can print three of four forms is better off than one who gets nothing.
     *
     * @param array<string, mixed> $contract
     *
     * @return list<array{key: string, bytes: string}>
     */
    private function render(array $contract): array
    {
        $sheets = [];

        foreach ($this->providerForms($contract) as $sheet) {
            $bytes = self::fromPdfHeader((string) ($sheet['bytes'] ?? ''));

            if (empty($sheet['ok']) || $bytes === null) {
                continue;
            }

            $sheets[] = ['key' => (string) $sheet['key'], 'bytes' => $bytes];
        }

        if ($sheets !== []) {
            return $sheets;
        }

        $summary = $this->summary($contract);

        return $summary === null ? [] : [['key' => self::KIND, 'bytes' => $summary]];
    }

    /**
     * @param array<string, mixed> $contract
     *
     * @return list<array{key: string, ok: bool, error?: string, bytes?: string, filename?: string}>
     */
    private function providerForms(array $contract): array
    {
        if (! class_exists(ECRM_FormFill::class)) {
            return [];
        }

        $reporting = error_reporting(0);
        ob_start();

        try {
            $sheets = ECRM_FormFill::fill_all($contract);
        } catch (Throwable) {
            $sheets = [];
        } finally {
            ob_end_clean();
            error_reporting($reporting);
        }

        return $sheets;
    }

    /**
     * @param array<string, mixed> $contract
     */
    private function summary(array $contract): ?string
    {
        $reporting = error_reporting(0);
        ob_start();

        try {
            $bytes = ECRM_PDF::build($contract);
        } catch (Throwable) {
            $bytes = '';
        } finally {
            ob_end_clean();
            error_reporting($reporting);
        }

        return self::fromPdfHeader($bytes);
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

    /**
     * The bytes from the PDF header on, or null when there is no header.
     *
     * These libraries write to stdout as they go. Buffering catches that, but
     * a notice raised before the buffer opened still ends up in front of the
     * header and corrupts the file, so anything preceding "%PDF-" is discarded
     * rather than trusted — and a payload without a header at all is not a
     * document, it is an error message.
     */
    private static function fromPdfHeader(string $bytes): ?string
    {
        $start = strpos($bytes, '%PDF-');

        // substr from zero returns the string unchanged, so no branch is needed.
        return $start === false ? null : substr($bytes, $start);
    }
}
