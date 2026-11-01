<?php

/**
 * (346) GET /customers/{id}/history -- το «Ιστορικό» της καρτέλας πελάτη.
 *
 * Λεπτός: ΠΟΙΕΣ γραμμές μαζεύονται, εδώ· ΠΩΣ διαβάζεται η καθεμιά, στο
 * Domain\Customer\CustomerHistory· το SQL στο Persistence. Ίδια ορατότητα με
 * το card(): ο πελάτης πρέπει να είναι reachable, οι συμβάσεις έρχονται από
 * το scoped ContractQueries::forCustomer(), και όλα τα υπόλοιπα κρέμονται από
 * αυτές τις συμβάσεις — εκτός από τις εργασίες, που έχουν δικό τους κανόνα.
 *
 * Χωρίς cache: φορτώνεται από τη βάση κάθε φορά που ανοίγει η καρτέλα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Docs;
use ECRM_Leads;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Customer\CustomerHistory;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\CustomerEventRepository;
use EnergyCRM\Persistence\CustomerHistoryQueries;
use EnergyCRM\Persistence\CustomerNoteRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\TaskRepository;
use WP_REST_Request;
use WP_REST_Response;

final class CustomerHistoryController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly CustomerRepository $customers,
        private readonly ContractQueries $queries,
        private readonly CustomerHistoryQueries $history,
        private readonly TaskRepository $tasks,
        private readonly CustomerNoteRepository $notes,
        private readonly CustomerEventRepository $edits,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/customers/(?P<id>\d+)/history', [
            'methods'             => 'GET',
            'callback'            => [$this, 'show'],
            'permission_callback' => Guards::crmUser(),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $scope      = $this->scopes->forCurrentUser();
        $customerId = (int) $request['id'];

        if (! $this->customers->isReachable($customerId, $scope)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Ο πελάτης δεν βρέθηκε.'], 404);
        }

        $contracts   = $this->queries->forCustomer($scope, $customerId);
        $contractIds = array_map(static fn (array $c): int => (int) $c['id'], $contracts);

        $result = CustomerHistory::compose(
            [
                'contracts' => $contracts,
                'events'    => $this->history->events($contractIds),
                'tasks'     => $this->tasks->forCustomerHistory($scope, $customerId, $contractIds),
                'notes'     => $this->notes->forCustomer($customerId),
                'edits'     => $this->edits->forCustomer($customerId, 500),
                'files'     => $this->history->files($contractIds),
                'leads'     => $this->history->leadOrigins($contractIds),
            ],
            ECRM_Docs::kinds(),
            ECRM_Leads::sources()
        );

        return new WP_REST_Response([
            'ok'        => true,
            'items'     => $this->withActorNames($result['items']),
            'counts'    => $result['counts'],
            'truncated' => $result['truncated'],
        ], 200);
    }

    /**
     * actor_id → όνομα με ΕΝΑ get_users(). Το id δεν φεύγει προς τον browser.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function withActorNames(array $items): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (array $i): int => (int) ($i['actor_id'] ?? 0), $items)
        )));

        $names = [];

        foreach ($ids === [] ? [] : get_users(['include' => $ids, 'fields' => ['ID', 'display_name']]) as $user) {
            $names[(int) $user->ID] = $user->display_name;
        }

        foreach ($items as $index => $item) {
            $uid = (int) ($item['actor_id'] ?? 0);

            // Τα έγγραφα δεν κρατούν ποιος τα ανέβασε: κενό, όχι «Σύστημα».
            $items[$index]['actor'] = $item['kind'] === 'doc'
                ? ''
                : ($uid > 0 ? ($names[$uid] ?? '—') : 'Σύστημα');
            unset($items[$index]['actor_id']);
        }

        return $items;
    }
}
