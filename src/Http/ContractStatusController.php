<?php

/**
 * POST   /contracts/{id}/status  move a contract along the pipeline
 * DELETE /contracts/{id}         remove it, documents included
 *
 * The transition itself belongs to ContractLifecycle, which owns the event log,
 * the in-app notification and the SMS. This controller decides whether the
 * caller may ask; the lifecycle decides what asking means.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Docs;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ContractStatusController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly FileRepository $files,
        private readonly ContractLifecycle $lifecycle,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)/status', [
            'methods'             => 'POST',
            'callback'            => [$this, 'change'],
            'permission_callback' => Guards::needs(Capability::CHANGE_STATUS),
            'args'                => [
                'id'     => ['type' => 'integer', 'required' => true],
                'status' => [
                    'type'     => 'string',
                    'required' => true,
                    'enum'     => array_column(ContractStatus::cases(), 'value'),
                ],
                'message' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);

        register_rest_route(Router::NAMESPACE, '/contracts/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'destroy'],
            'permission_callback' => Guards::needs(Capability::DELETE_CONTRACT),
            'args'                => ['id' => ['type' => 'integer', 'required' => true]],
        ]);
    }

    public function change(WP_REST_Request $request): WP_REST_Response
    {
        $scope   = $this->scopes->forCurrentUser();
        $id      = (int) $request['id'];
        $target  = ContractStatus::from((string) $request['status']);
        $current = $this->contracts->find($id, $scope);

        if ($current === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        $from = (string) $current['status'];

        if ($from === $target->value) {
            return new WP_REST_Response(['ok' => true, 'status' => $target->value], 200);
        }

        $source = ContractStatus::tryFromSlug($from);

        if ($source !== null && ! $source->canMoveTo($target)) {
            return new WP_REST_Response([
                'ok'      => false,
                'error'   => sprintf(
                    'Δεν επιτρέπεται μετάβαση από «%s» σε «%s».',
                    $source->label(),
                    $target->label()
                ),
                'allowed' => array_map(
                    static fn (ContractStatus $s): array => ['status' => $s->value, 'label' => $s->label()],
                    $source->allowedNext()
                ),
            ], 409);
        }

        // Documents gate: some statuses may not be entered with paperwork missing.
        if (in_array($target->value, ECRM_Docs::gate_statuses(), true)) {
            $missing = ECRM_Docs::missing_labels($id, (string) ($current['activation_type'] ?? ''));

            if ($missing) {
                return new WP_REST_Response([
                    'ok'      => false,
                    'error'   => 'Λείπουν δικαιολογητικά: ' . implode(', ', $missing),
                    'missing' => $missing,
                ], 422);
            }
        }

        $this->lifecycle->moveTo($id, $target->value, [
            'user_id' => $scope->actorId(),
            'from'    => $from,
            'message' => (string) $request['message'] ?: null,
        ]);

        return new WP_REST_Response(['ok' => true, 'status' => $target->value], 200);
    }

    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $scope = $this->scopes->forCurrentUser();
        $id    = (int) $request['id'];

        if (! $this->contracts->exists($id, $scope)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Δεν βρέθηκε η σύμβαση.'], 404);
        }

        // Bytes before rows: the foreign key would drop the file records and
        // leave the documents themselves on disk with nothing pointing at them.
        $this->files->purgeForContracts([$id]);

        return $this->contracts->delete($id, $scope)
            ? new WP_REST_Response(['ok' => true], 200)
            : new WP_REST_Response(['ok' => false, 'error' => 'Η διαγραφή απέτυχε.'], 500);
    }
}
