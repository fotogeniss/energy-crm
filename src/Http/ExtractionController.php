<?php

/**
 * POST /extract — read a customer's documents and return the fields found.
 *
 * The uploads never touch disk: they are read from the temporary files PHP
 * already holds, sent to the model, and forgotten. Nothing is stored until the
 * agent saves the contract, which is what makes an abandoned extraction leave
 * no identity documents behind.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Extractor;
use ECRM_RateLimit;
use WP_REST_Request;
use WP_REST_Response;

final class ExtractionController implements Controller
{
    private const ALLOWED_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf',
    ];

    /** An application needs an ID and a bill; ten is generous. */
    private const MAX_DOCUMENTS = 10;

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/extract', [
            'methods'             => 'POST',
            'callback'            => [$this, 'extract'],
            'permission_callback' => Guards::crmUser(),
        ]);
    }

    public function extract(WP_REST_Request $request): WP_REST_Response
    {
        // Each call costs money and takes seconds; a stuck client should not be
        // able to run up a bill.
        if (! ECRM_RateLimit::allow('extract', 60, 300)) {
            return ECRM_RateLimit::too_many();
        }

        $uploads = $request->get_file_params()['files'] ?? null;

        if (empty($uploads)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν ανέβηκαν αρχεία.'], 400);
        }

        $kinds     = (array) $request->get_param('kinds');
        $documents = [];

        foreach (self::normalise($uploads) as $index => $upload) {
            if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                continue;
            }

            $mime = self::mimeOf($upload);

            if ($mime === null) {
                continue;
            }

            $documents[] = [
                'path' => $upload['tmp_name'],
                'mime' => $mime,
                'kind' => sanitize_text_field((string) ($kinds[$index] ?? 'other')),
            ];
        }

        if ($documents === []) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Μη υποστηριζόμενα αρχεία (μόνο PDF/JPG/PNG).'],
                400
            );
        }

        $result = ECRM_Extractor::extract(array_slice($documents, 0, self::MAX_DOCUMENTS));

        return new WP_REST_Response($result, $result['ok'] ? 200 : 502);
    }

    /**
     * The declared type when we accept it, otherwise what the extension says.
     *
     * Browsers report inconsistent types for the same file, so a rejected
     * declaration is worth a second look before the document is discarded.
     *
     * @param array<string, mixed> $upload
     */
    private static function mimeOf(array $upload): ?string
    {
        $declared = (string) ($upload['type'] ?? '');

        if (in_array($declared, self::ALLOWED_MIMES, true)) {
            return $declared;
        }

        $guessed = wp_check_filetype((string) ($upload['name'] ?? ''))['type'] ?? '';

        return in_array($guessed, self::ALLOWED_MIMES, true) ? $guessed : null;
    }

    /**
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
            ];
        }

        return $out;
    }
}
