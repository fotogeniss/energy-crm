<?php

/**
 * GET /contracts/{id}/pdf            the CRM's own contract document
 * GET /contracts/{id}/provider-form  the provider's application, filled in
 * GET /contracts/export              the whole selection as a spreadsheet
 *
 * All three read a contract and hand back base64. None of them writes anything,
 * so the scoped read is the whole of the authorisation.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Export;
use ECRM_FormFill;
use ECRM_PDF;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Infrastructure\ContractDocuments;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\FileRepository;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

final class ContractDocumentsController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly FileRepository $files,
        private readonly ContractDocuments $documents,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/pdf', [
            'methods'             => 'GET',
            'callback'            => [$this, 'contractPdf'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'    => ['type' => 'integer', 'required' => true],
                'store' => ['type' => 'boolean', 'default' => false],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/provider-form', [
            'methods'             => 'GET',
            'callback'            => [$this, 'providerForm'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);

        register_rest_route(Router::NAMESPACE, '/contracts/export', [
            'methods'             => 'GET',
            'callback'            => [$this, 'export'],
            'permission_callback' => Guards::needs(Capability::EXPORT_DATA),
            /*
             * "No filter" is the normal case here, and every optional argument
             * below has to say so in a way WordPress accepts. It did not:
             * validation runs before the permission callback, so a schema that
             * rejects its own defaults answers 400 to everyone, always.
             *
             *   - `from`/`to` defaulted to '' against a pattern that demands a
             *     date. Asking for an export without a date range — the usual
             *     way to ask — was rejected before the handler ran.
             *   - `partner` is declared integer, and the export dialog sends
             *     `partner=` for "everyone". An empty string is not an integer.
             *
             * Both are widened rather than tightened at the caller: the query
             * string is already out there in browsers, and a stricter schema
             * would keep failing it.
             */
            'args'                => [
                'scope'   => ['type' => 'string', 'default' => 'own', 'enum' => ['own', 'team']],
                'partner' => [
                    'type'              => 'integer',
                    'default'           => 0,
                    'minimum'           => 0,
                    'validate_callback' => static fn (mixed $value): bool =>
                        $value === '' || rest_is_integer($value),
                    // Replaces the minimum, which the custom validator skips.
                    'sanitize_callback' => 'absint',
                ],
                'status'  => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'q'       => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'from'    => ['type' => 'string', 'default' => '', 'pattern' => '^(\d{4}-\d{2}-\d{2})?$'],
                'to'      => ['type' => 'string', 'default' => '', 'pattern' => '^(\d{4}-\d{2}-\d{2})?$'],
            ],
        ]);
    }

    public function contractPdf(WP_REST_Request $request): WP_REST_Response
    {
        $id  = (int) $request['id'];
        $row = $this->contracts->findDetailed($id, $this->scopes->forCurrentUser());

        if ($row === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        if ($request['store']) {
            $stored = $this->documents->store($id);

            return new WP_REST_Response(
                $stored
                    ? ['ok' => true, 'stored' => true, 'message' => 'Το PDF δημιουργήθηκε και αποθηκεύτηκε.']
                    : ['ok' => false, 'error' => 'Δεν ήταν δυνατή η δημιουργία του PDF.'],
                $stored ? 200 : 500
            );
        }

        $bytes = self::render(static fn (): string => ECRM_PDF::build($row));

        if ($bytes === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Σφάλμα δημιουργίας PDF.'], 500);
        }

        return new WP_REST_Response([
            'ok'       => true,
            'filename' => ($row['code'] ?: 'symvasi-' . $id) . '.pdf',
            'mime'     => 'application/pdf',
            'b64'      => base64_encode($bytes),
        ], 200);
    }

    public function providerForm(WP_REST_Request $request): WP_REST_Response
    {
        $id  = (int) $request['id'];
        $row = $this->contracts->findDetailed($id, $this->scopes->forCurrentUser());

        if ($row === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $result = ECRM_FormFill::fill($row, $this->files->latestPathOfKind($id, 'signature'));

        if (empty($result['ok'])) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => $result['error'] ?? 'Αποτυχία.'],
                422
            );
        }

        return new WP_REST_Response([
            'ok'       => true,
            'filename' => $result['filename'],
            'mime'     => 'application/pdf',
            'b64'      => base64_encode($result['bytes']),
        ], 200);
    }

    public function export(WP_REST_Request $request): WP_REST_Response
    {
        $scope   = $this->scopes->forCurrentUser();
        $partner = (int) $request['partner'];

        // A named partner narrows the export, but only to someone already in
        // scope; otherwise it falls back rather than widening.
        if ($partner > 0 && $scope->includes($partner)) {
            $userIds = [$partner];
        } elseif ($request['scope'] === 'team') {
            $userIds = $scope->userIds();
        } else {
            $userIds = [$scope->actorId()];
        }

        $data = ECRM_Export::contracts_dataset(
            (string) $request['status'],
            (string) $request['q'],
            [],
            $userIds,
            (string) $request['from'],
            (string) $request['to']
        );

        return new WP_REST_Response([
            'ok'       => true,
            'filename' => 'symvaseis-' . gmdate('Ymd-Hi') . '.xlsx',
            'mime'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'count'    => count($data['rows']),
            'b64'      => base64_encode(ECRM_Export::build_xlsx($data['headers'], $data['rows'])),
        ], 200);
    }

    /**
     * Run a PDF builder with output buffered and notices silenced.
     *
     * The libraries here write to stdout as they go, and a single stray notice
     * lands in front of the PDF header and corrupts the file. Anything before
     * "%PDF-" is therefore discarded rather than trusted.
     *
     * @param callable(): string $build
     */
    private static function render(callable $build): ?string
    {
        set_time_limit(60);
        $reporting = error_reporting(0);

        // Buffering opens outside the try and closes in finally, so it is
        // balanced on every path. Guarding with ob_get_level() would only be
        // papering over a start and an end that could get out of step.
        ob_start();

        try {
            $bytes = $build();
        } catch (Throwable) {
            return null;
        } finally {
            ob_end_clean();
            error_reporting($reporting);
        }

        $start = strpos($bytes, '%PDF-');

        if ($start === false) {
            return null;
        }

        // substr from zero returns the string unchanged, so no branch is needed.
        return substr($bytes, $start);
    }
}
