<?php

/**
 * (331) GET /programs/{id}/script -- «Τι λέω στον πελάτη».
 *
 * Το κείμενο το φτιάχνει το Domain\Sales\SalesScript· εδώ μόνο ποιο πρόγραμμα
 * (ενεργό, από πάροχο που βλέπει ο χρήστης) και τα στοιχεία της αίτησης που
 * αλλάζουν τις γραμμές: τρόπος πληρωμής, προτεινόμενη εγγύηση, εξοικονόμηση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\ProviderVisibility;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Sales\SalesScript;
use EnergyCRM\Persistence\ProviderRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SalesScriptController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ProviderVisibility $providerAccess,
        private readonly ProviderRepository $providers,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/programs/(?P<id>\d+)/script', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'             => ['type' => 'integer', 'required' => true],
                'payment_method' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key'],
                'guarantee'      => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
                'saving'         => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        $id      = (int) $request['id'];
        $access  = $this->providerAccess->forScope($scope);
        $program = null;

        foreach ($this->providers->activePrograms() as $row) {
            if ((int) $row['id'] === $id && $access->allows((int) $row['provider_id'])) {
                $program = $row;
                break;
            }
        }

        if ($program === null || ! in_array((string) $program['energy_type'], ['power', 'gas'], true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε το πρόγραμμα.'], 404);
        }

        $script = SalesScript::for($program, [
            'payment_method' => (string) $request['payment_method'],
            'guarantee'      => self::amount((string) $request['guarantee']),
            'saving'         => self::amount((string) $request['saving']),
        ]);

        return new WP_REST_Response([
            'ok'            => true,
            'label'         => $script['label'],
            'lines'         => $script['lines'],
            'missing_price' => $script['missing_price'],
            'plain'         => SalesScript::plain((string) $program['name'], $script['lines']),
        ], 200);
    }

    private static function amount(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));

        return $value !== '' && is_numeric($value) ? (float) $value : null;
    }
}
