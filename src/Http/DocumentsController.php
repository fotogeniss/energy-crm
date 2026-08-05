<?php

/**
 * POST /contracts/{id}/files  attach scanned documents
 * GET  /file/{id}             stream one back, behind a signed token
 *
 * These carry identity documents, so two rules apply throughout: the contract
 * is resolved through a scoped repository before anything is written, and the
 * bytes never become reachable by URL — ECRM_Files::serve checks a signed token
 * and the requester's scope before it reads from disk.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Files;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;

final class DocumentsController implements Controller
{
    /** What a scanned ID or bill may be. Anything else is not stored. */
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly FileRepository $files,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/files', [
            'methods'             => 'POST',
            'callback'            => [$this, 'upload'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);

        register_rest_route(Router::NAMESPACE, '/file/(?P<id>\d+)', [
            'methods'  => 'GET',
            'callback' => [ECRM_Files::class, 'serve'],
            // Deliberately open: serve() verifies a short-lived signed token and
            // that the user it was issued to may still see the contract. A login
            // check here would add nothing and break the emailed links.
            'permission_callback' => '__return_true',
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function upload(WP_REST_Request $request): WP_REST_Response
    {
        $scope      = $this->scopes->forCurrentUser();
        $contractId = (int) $request['id'];

        if (! $this->contracts->exists($contractId, $scope)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $uploads = $request->get_file_params()['files'] ?? null;

        if (empty($uploads)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν ανέβηκαν αρχεία.'], 400);
        }

        $kinds = (array) $request->get_param('kinds');
        $saved = [];

        foreach (self::normalise($uploads) as $index => $upload) {
            $stored = ECRM_Files::store($upload, self::ALLOWED_MIMES);

            // Rejected by type or a failed move: skip it, keep the rest.
            if ($stored === null) {
                continue;
            }

            $kind   = sanitize_text_field((string) ($kinds[$index] ?? 'other'));
            $fileId = $this->files->attach(
                $contractId,
                $kind,
                $stored['filename'],
                $stored['mime'],
                $stored['path']
            );

            if ($fileId <= 0) {
                continue;
            }

            $saved[] = [
                'id'       => $fileId,
                'filename' => $stored['filename'],
                'url'      => ECRM_Files::url($fileId),
                'kind'     => $kind,
            ];
        }

        return new WP_REST_Response(
            ['ok' => true, 'saved' => count($saved), 'files' => $saved],
            200
        );
    }

    /**
     * PHP hands multiple uploads back as parallel arrays rather than a list of
     * files; this turns them into one entry per file.
     *
     * @param array<string, mixed> $uploads
     *
     * @return list<array<string, mixed>>
     */
    private static function normalise(array $uploads): array
    {
        if (! is_array($uploads['name'] ?? null)) {
            return [$uploads];
        }

        $out = [];

        foreach (array_keys($uploads['name']) as $index) {
            $out[] = [
                'name'     => $uploads['name'][$index] ?? '',
                'type'     => $uploads['type'][$index] ?? '',
                'tmp_name' => $uploads['tmp_name'][$index] ?? '',
                'error'    => $uploads['error'][$index] ?? UPLOAD_ERR_OK,
                'size'     => $uploads['size'][$index] ?? 0,
            ];
        }

        return $out;
    }
}
