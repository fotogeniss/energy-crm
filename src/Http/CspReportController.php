<?php

/**
 * (394) `POST /ecrm/v1/csp-report`: εδώ στέλνει ο browser ό,τι θα μπλόκαρε η
 * Content-Security-Policy. Δημόσιο, γιατί η αναφορά έρχεται και από τη
 * σελίδα παρακολούθησης του πελάτη, που δεν έχει σύνδεση.
 *
 * Τι μπορεί να κάνει κάποιος κακόβουλος: να γράψει ψεύτικες γραμμές στη λίστα
 * της «Υγείας». Γι' αυτό: όριο ανά IP, έως 16 KB σώμα, έως 10 αναφορές ανά
 * αίτηση, μόνο γνωστά πεδία, κομμένα και χωρίς query string, έως 100 γραμμές
 * συνολικά, και escape στην εμφάνιση. Δεν διαβάζει και δεν επιστρέφει τίποτα.
 *
 * Laravel-ready: μία route χωρίς auth με throttle middleware.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_RateLimit;
use EnergyCRM\Domain\Security\CspPolicy;
use EnergyCRM\Infrastructure\CspReports;
use WP_REST_Request;
use WP_REST_Response;

final class CspReportController implements Controller
{
    private const MAX_BODY = 16384;

    private const MAX_PER_REQUEST = 10;

    public function __construct(private readonly CspReports $reports)
    {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/csp-report', [
            'methods'             => 'POST',
            'callback'            => [$this, 'receive'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function receive(WP_REST_Request $request): WP_REST_Response
    {
        $bucket = 'ip' . md5(\EnergyCRM\Infrastructure\RequestIp::current());

        if (! ECRM_RateLimit::allow('csp', 60, 600, $bucket)) {
            return new WP_REST_Response(null, 429);
        }

        $body = $request->get_body();

        if ($body === '' || strlen($body) > self::MAX_BODY) {
            return new WP_REST_Response(null, 204);
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return new WP_REST_Response(null, 204);
        }

        // report-uri: ένα αντικείμενο. Reporting API: λίστα αντικειμένων.
        $list = array_is_list($decoded) ? $decoded : [$decoded];

        foreach (array_slice($list, 0, self::MAX_PER_REQUEST) as $one) {
            $normalised = is_array($one) ? CspPolicy::normalise($one) : null;

            if ($normalised !== null) {
                $this->reports->record($normalised);
            }
        }

        return new WP_REST_Response(null, 204);
    }
}
