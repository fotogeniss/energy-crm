<?php

/**
 * GET /lookup/afm — company name and address from the EU VAT register.
 *
 * Calls out to VIES, which is public, free, occasionally slow and frequently
 * down. Every failure mode returns a plain message rather than an exception:
 * the agent can always type the details by hand, so a register outage must not
 * block the application.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_RateLimit;
use ECRM_Validate;
use EnergyCRM\Domain\Customer\GreekAddress;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class VatLookupController implements Controller
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/EL/vat/';

    /** VIES answers "---" when it has the registration but not the detail. */
    private const NO_VALUE = '---';

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/lookup/afm', [
            'methods'             => 'GET',
            'callback'            => [$this, 'lookup'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'afm' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function lookup(WP_REST_Request $request): WP_REST_Response
    {
        // The register is someone else's service; we do not hammer it.
        if (! ECRM_RateLimit::allow('afm', 30, 300)) {
            return ECRM_RateLimit::too_many();
        }

        $afm = (string) preg_replace('/\D+/', '', (string) $request['afm']);

        // Check digit first: a typo costs nothing to catch here and a round
        // trip to Brussels to catch there.
        if (! ECRM_Validate::afm($afm)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Μη έγκυρο ΑΦΜ.'], 422);
        }

        $response = wp_remote_get(self::ENDPOINT . rawurlencode($afm), [
            'timeout' => 12,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if ($response instanceof WP_Error) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Αποτυχία σύνδεσης στο μητρώο (VIES).'],
                502
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data   = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status !== 200 || ! is_array($data)) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Το μητρώο VIES δεν απάντησε (HTTP ' . $status . ').'],
                502
            );
        }

        if (empty($data['isValid'])) {
            return new WP_REST_Response(['ok' => true, 'valid' => false, 'afm' => $afm], 200);
        }

        $name    = self::value((string) ($data['name'] ?? ''));
        $address = self::value((string) ($data['address'] ?? ''));

        return new WP_REST_Response([
            'ok'      => true,
            'valid'   => true,
            'afm'     => $afm,
            'name'    => $name,
            'address' => $address,
            'parsed'  => GreekAddress::parse($address),
        ], 200);
    }

    private static function value(string $field): string
    {
        $field = trim($field);

        return $field === self::NO_VALUE ? '' : $field;
    }
}
