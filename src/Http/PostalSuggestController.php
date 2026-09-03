<?php

/**
 * GET /postal/suggest — πρόταση πόλης/νομού από ΤΚ, δίπλα στο πεδίο της
 * φόρμας. Ίδιο σχήμα με το `/guarantee/suggest`: ένα resource, ρητό args
 * schema, καμία εγγραφή στη βάση -- ο πωλητής αποφασίζει με το κλικ του στο
 * «Χρήση», ΠΟΤΕ αυτόματο γέμισμα.
 *
 * CHANGELOG (216). Η αναζήτηση ζει στο `Domain\Address\PostalLookup` -- εδώ
 * μένει μόνο η μετάφραση αιτήματος→κλήση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Domain\Address\PostalLookup;
use EnergyCRM\Plugin;
use WP_REST_Request;
use WP_REST_Response;

final class PostalSuggestController implements Controller
{
    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/postal/suggest', [
            'methods'             => 'GET',
            'callback'            => [$this, 'suggest'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'postal_code' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function suggest(WP_REST_Request $request): WP_REST_Response
    {
        $postalCode = (string) $request['postal_code'];
        $dataDir    = (Plugin::instance()?->dir() ?? '') . 'assets/data';

        $nomos = PostalLookup::nomos($postalCode, $dataDir);

        return new WP_REST_Response([
            'ok'         => true,
            'nomos'      => $nomos['nomos'] ?? null,
            'diamerisma' => $nomos['diamerisma'] ?? null,
            'city'       => PostalLookup::city($postalCode, $dataDir),
        ], 200);
    }
}
