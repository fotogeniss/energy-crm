<?php

/**
 * (348) POST   /contracts/{id}/remarks          -- «Παρατηρήσεις Protergia» / «Παρατηρήσεις Agent».
 *       DELETE /contracts/{id}/remarks/{event}  -- διαγραφή (αυτός που την έγραψε, Back office, admin).
 *
 * Λεπτός: ο κανόνας (ποιος γράφει τι, σε ποια κατάσταση) ζει στο
 * Domain\Contract\PendingRemarks. Την αίτηση τη βρίσκει το scoped find(), άρα
 * όποιος δεν τη βλέπει παίρνει 404· το Back office τη βλέπει (VIEW_ALL).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Contract\PendingRemarks;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\PendingRemarkRepository;
use WP_REST_Request;
use WP_REST_Response;

final class PendingRemarksController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly PendingRemarkRepository $remarks,
    ) {
    }

    /** Ο Back office και ο διαχειριστής: γράφουν «Παρατηρήσεις Protergia» και σβήνουν κάθε παρατήρηση. */
    public static function canWriteProvider(): bool
    {
        return current_user_can('manage_options') || current_user_can(Capability::VIEW_ALL);
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/remarks', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'   => ['type' => 'integer', 'required' => true],
                'kind' => ['type' => 'string', 'required' => true, 'enum' => PendingRemarks::kinds()],
                'text' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field'],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/remarks/(?P<event>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'destroy'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'id'    => ['type' => 'integer', 'required' => true],
                'event' => ['type' => 'integer', 'required' => true],
            ],
        ]);
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();
        $id    = (int) $request['id'];

        if ($this->contracts->find($id, $scope) === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $remark = $this->remarks->find($id, (int) $request['event']);

        if ($remark === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η παρατήρηση.'], 404);
        }

        if (! PendingRemarks::canDelete($scope->actorId(), $remark['user_id'], self::canWriteProvider())) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Τη διαγράφει μόνο αυτός που την έγραψε, ο Back office ή ο διαχειριστής.'],
                403
            );
        }

        $this->remarks->remove($id, $scope->actorId(), $remark['id']);

        return new WP_REST_Response(['ok' => true, 'remarks' => $this->remarks->current($id)], 200);
    }

    public function save(WP_REST_Request $request): WP_REST_Response
    {
        $scope    = $this->scopes->forCurrentUser();
        $id       = (int) $request['id'];
        $contract = $this->contracts->find($id, $scope);

        if ($contract === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $kind    = (string) $request['kind'];
        $problem = PendingRemarks::problem(
            $kind,
            (string) ($contract['status'] ?? ''),
            (string) $request['text'],
            self::canWriteProvider()
        );

        if ($problem !== null) {
            return new WP_REST_Response(['ok' => false, 'error' => $problem], 422);
        }

        $this->remarks->add($id, $scope->actorId(), $kind, (string) $request['text']);

        return new WP_REST_Response(['ok' => true, 'remarks' => $this->remarks->current($id)], 200);
    }
}
