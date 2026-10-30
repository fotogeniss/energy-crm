<?php

/**
 * GET  /contracts/{id}/companion  υπάρχει ή μπορεί να γίνει δίδυμη αίτηση;
 * POST /contracts/{id}/companion  φτιάξε την (ή δώσε την υπάρχουσα)
 *
 * (326) «+ Αέριο / + Ρεύμα στον ίδιο πελάτη». Ίδιο σχήμα με την Ανανέωση
 * (RenewalsController): νέα πρόχειρη αίτηση, ιδιοκτήτης αυτός που πάτησε το
 * κουμπί, τίποτα από την υπογραφή ή τη συναίνεση της πρώτης. Τι περνάει και
 * γιατί: `Domain\Contract\CompanionDraft`.
 *
 * Δεύτερη δίδυμη δεν φτιάχνεται: αν υπάρχει ήδη μη ακυρωμένη αίτηση του
 * άλλου είδους στον ίδιο πελάτη και πάροχο, το POST την επιστρέφει αντί να
 * γράψει νέα -- το διπλό πάτημα δεν βγάζει δύο αιτήσεις.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\NotAuthenticated;
use EnergyCRM\Access\ProviderVisibility;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Contract\CompanionDraft;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\ProviderRepository;
use WP_REST_Request;
use WP_REST_Response;

final class CompanionController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly ContractQueries $queries,
        private readonly EventRepository $events,
        private readonly ProviderVisibility $providerAccess,
        private readonly ProviderRepository $providers,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/companion', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'show'],
                'permission_callback' => Guards::crmUser(),
                'args'                => ['id' => ['type' => 'integer', 'required' => true]],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'create'],
                'permission_callback' => Guards::crmUser(),
                'args'                => ['id' => ['type' => 'integer', 'required' => true]],
            ],
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof WP_REST_Response) {
            return $ctx;
        }

        [, $source, $target, $existing] = $ctx;

        return new WP_REST_Response([
            'ok'        => true,
            'energy'    => $target,
            'available' => $target !== '' && $this->offers((int) ($source['provider_id'] ?? 0), $target),
            'existing'  => $existing,
        ], 200);
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof WP_REST_Response) {
            return $ctx;
        }

        [$scope, $source, $target, $existing] = $ctx;

        if ($existing !== null) {
            return new WP_REST_Response([
                'ok'          => true,
                'existing'    => true,
                'contract_id' => $existing['id'],
                'code'        => $existing['code'],
            ], 200);
        }

        $providerId = (int) ($source['provider_id'] ?? 0);

        if ($target === '' || ! $this->offers($providerId, $target)) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Ο πάροχος αυτής της αίτησης δεν έχει ενεργά προγράμματα '
                    . ($target === 'gas' ? 'φυσικού αερίου.' : 'ρεύματος.'),
            ], 422);
        }

        if ($providerId > 0 && ! $this->providerAccess->forScope($scope)->allows($providerId)) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Δεν έχεις πρόσβαση στον πάροχο αυτής της αίτησης. Ζήτα από τον υπεύθυνό σου.',
            ], 403);
        }

        $draft           = CompanionDraft::from($source);
        $draft['status'] = 'draft';
        $draft['notes']  = CompanionDraft::note($source);

        $contractId = $this->contracts->create($draft, $scope);

        if ($contractId <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Η δημιουργία απέτυχε.'], 500);
        }

        $code = $this->contracts->assignCode($contractId, $scope);

        $this->events->record($contractId, $scope->actorId(), 'created', [
            'to_status' => 'draft',
            'message'   => $draft['notes'],
        ]);

        return new WP_REST_Response([
            'ok'          => true,
            'existing'    => false,
            'contract_id' => $contractId,
            'code'        => $code,
        ], 200);
    }

    /**
     * @return array{
     *   0: \EnergyCRM\Access\UserScope,
     *   1: array<string, mixed>,
     *   2: string,
     *   3: array{id: int, code: string, status: string, program_name: string}|null
     * }|WP_REST_Response
     */
    private function context(WP_REST_Request $request): array|WP_REST_Response
    {
        try {
            $scope = $this->scopes->forCurrentUser();
        } catch (NotAuthenticated) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Απαιτείται σύνδεση.'], 401);
        }

        $source = $this->contracts->find((int) $request['id'], $scope);

        if ($source === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $target   = CompanionDraft::target((string) ($source['energy_type'] ?? ''));
        $existing = $target === '' ? null : $this->queries->companion(
            $scope,
            (int) ($source['customer_id'] ?? 0),
            (int) ($source['provider_id'] ?? 0),
            $target,
            (int) ($source['id'] ?? 0)
        );

        return [$scope, $source, $target, $existing];
    }

    /** Έχει ο πάροχος ενεργό πρόγραμμα αυτού του είδους; */
    private function offers(int $providerId, string $energy): bool
    {
        foreach ($this->providers->activePrograms() as $program) {
            if ((int) ($program['provider_id'] ?? 0) === $providerId && ($program['energy_type'] ?? '') === $energy) {
                return true;
            }
        }

        return false;
    }
}
