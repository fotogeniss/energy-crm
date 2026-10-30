<?php

/**
 * (332) GET /programs/{id}/package-fit?kwh= -- ταιριάζει το μέγεθος Picasso;
 *
 * Η απάντηση βγαίνει από το Domain\Pricing\PackageFit. Εδώ μόνο: ποιο
 * πρόγραμμα (ενεργό, από πάροχο που βλέπει ο χρήστης), και ποιο ενεργό
 * πρόγραμμα είναι το Πακέτο Αναφοράς, για το κουμπί «Αλλαγή σε …».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\ProviderVisibility;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Pricing\PackageFit;
use EnergyCRM\Domain\Pricing\ProgramPricing;
use EnergyCRM\Persistence\ProviderRepository;
use WP_REST_Request;
use WP_REST_Response;

final class PackageFitController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ProviderVisibility $providerAccess,
        private readonly ProviderRepository $providers,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/programs/(?P<id>\d+)/package-fit', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'  => ['type' => 'integer', 'required' => true],
                'kwh' => ['type' => 'number', 'default' => 0],
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

        $access  = $this->providerAccess->forScope($scope);
        $visible = array_values(array_filter(
            $this->providers->activePrograms(),
            static fn (array $row): bool => $access->allows((int) $row['provider_id'])
        ));

        $id      = (int) $request['id'];
        $program = null;

        foreach ($visible as $row) {
            if ((int) $row['id'] === $id) {
                $program = $row;
            }
        }

        if ($program === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε το πρόγραμμα.'], 404);
        }

        $rule = ProgramPricing::rule($program);
        $fit  = $rule['kind'] === 'package' && isset($rule['family'])
            ? PackageFit::fit($rule, max(0.0, (float) $request['kwh']))
            : null;

        if ($fit === null) {
            return new WP_REST_Response(['ok' => true, 'applies' => false], 200);
        }

        $refProgram = null;

        foreach ($visible as $row) {
            if ((string) ($row['code'] ?? '') === $fit['reference']['code']) {
                $refProgram = (int) $row['id'];
            }
        }

        return new WP_REST_Response([
            'ok'         => true,
            'applies'    => true,
            'fits'       => $fit['fits'],
            'reference'  => ['label' => $fit['reference']['label'], 'program_id' => $refProgram],
            'chosen'     => $fit['chosen'] !== null ? $fit['chosen']['label'] : '',
            'settlement' => $fit['settlement'],
        ], 200);
    }
}
