<?php

/**
 * POST /import/parse  read a provider spreadsheet and preview what it matches
 * POST /import/apply  write the matched rows, or dry-run them
 *
 * Both delegate to ECRM_Import, which owns the spreadsheet reading. The value
 * added here is the capability check and an argument schema — the old handlers
 * repeated "may this user import" in both bodies.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Import;
use EnergyCRM\Access\Capability;
use WP_REST_Request;
use WP_REST_Response;

final class ImportController implements Controller
{
    /** Πόσες γραμμές δέχεται μία εισαγωγή. Μεγαλύτερο αρχείο χωρίζεται. */
    private const MAX_ROWS = 2000;

    public function routes(): void
    {
        $guard = Guards::needs(Capability::IMPORT_DATA);

        register_rest_route(Router::NAMESPACE, '/import/parse', [
            'methods'             => 'POST',
            'callback'            => [$this, 'parse'],
            'permission_callback' => $guard,
        ]);

        register_rest_route(Router::NAMESPACE, '/import/apply', [
            'methods'             => 'POST',
            'callback'            => [$this, 'apply'],
            'permission_callback' => $guard,
            'args'                => [
                'pairs' => [
                    'type'     => 'array',
                    'required' => true,
                    'minItems' => 1,
                    // Κάθε γραμμή είναι ένα ερώτημα συν μια μετάβαση. Χωρίς όριο,
                    // ένα αρχείο 20.000 γραμμών χτυπά το timeout της PHP με τις
                    // μισές αλλαγές γραμμένες και χωρίς απάντηση — ο χρήστης δεν
                    // μαθαίνει πού σταμάτησε και ξαναπατάει.
                    'maxItems' => self::MAX_ROWS,
                ],
                'dry' => ['type' => 'boolean', 'default' => false],
            ],
        ]);
    }

    public function parse(WP_REST_Request $request): WP_REST_Response
    {
        $file = $request->get_file_params()['file'] ?? null;

        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν ανέβηκε αρχείο.'], 400);
        }

        $result = ECRM_Import::parse(
            (string) $file['tmp_name'],
            (string) ($file['name'] ?? 'file.xlsx')
        );

        return new WP_REST_Response($result, $result['ok'] ? 200 : 400);
    }

    public function apply(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(
            ECRM_Import::apply((array) $request['pairs'], (bool) $request['dry']),
            200
        );
    }
}
