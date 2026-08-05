<?php

/**
 * POST /contracts/bulk — one endpoint, four operations.
 *
 * Which capability applies depends on what the request asked for, so it cannot
 * be settled by the route: `permission_callback` establishes the floor and each
 * branch checks its own. Splitting these into four endpoints would be tidier,
 * but the UI sends one selection and one action, and changing that contract is
 * a separate job from moving the code.
 *
 * Whatever ids arrive, only those the actor may reach are acted on — a stale
 * selection loses the rows it should not have had, not the whole batch.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Docs;
use ECRM_Export;
use ECRM_REST;
use EnergyCRM\Access\Capability;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\FileRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ContractsBulkController implements Controller
{
    /** Which capability each operation needs. */
    private const REQUIRES = [
        'status' => Capability::CHANGE_STATUS,
        'delete' => Capability::DELETE_CONTRACT,
        'assign' => Capability::ASSIGN_CONTRACT,
        'export' => Capability::EXPORT_DATA,
    ];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly FileRepository $files,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts/bulk', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'ids' => [
                    'type'     => 'array',
                    'required' => true,
                    'items'    => ['type' => 'integer'],
                ],
                'action' => [
                    'type'     => 'string',
                    'required' => true,
                    'enum'     => ['status', 'delete', 'assign', 'export'],
                ],
                'value' => ['type' => 'string', 'default' => ''],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $action = (string) $request['action'];
        $scope  = $this->scopes->forCurrentUser();

        if (! current_user_can(self::REQUIRES[$action])) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Δεν έχεις δικαίωμα για αυτή την ενέργεια.'],
                403
            );
        }

        $rows = $this->contracts->reachableAmong(
            array_map('intval', (array) $request['ids']),
            $scope
        );

        if ($rows === []) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Καμία προσβάσιμη σύμβαση.'], 403);
        }

        return match ($action) {
            'status' => $this->changeStatus($rows, (string) $request['value']),
            'delete' => $this->delete($rows, $scope),
            'assign' => $this->assign($rows, (int) $request['value'], $scope),
            'export' => $this->export($rows, $scope),
            // Unreachable: the route's enum rejects anything else before we get
            // here. Spelled out anyway, because a future action added to the
            // schema and forgotten here should fail loudly, not fall through.
            default  => new WP_REST_Response(
                ['ok' => false, 'error' => 'Άγνωστη ενέργεια.'],
                400
            ),
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function changeStatus(array $rows, string $to): WP_REST_Response
    {
        $target = ContractStatus::tryFromSlug($to);

        if ($target === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Μη έγκυρη κατάσταση.'], 400);
        }

        $gated    = in_array($target->value, ECRM_Docs::gate_statuses(), true);
        $updated  = 0;
        $skipped  = 0;
        $rejected = [];

        foreach ($rows as $row) {
            $id   = (int) $row['id'];
            $from = (string) $row['status'];

            if ($from === $target->value) {
                continue;
            }

            if ($gated && ECRM_Docs::missing_labels($id, (string) ($row['activation_type'] ?? ''))) {
                $skipped++;
                continue;
            }

            $source = ContractStatus::tryFromSlug($from);

            // The pipeline may refuse the move; report that rather than count
            // it as done, which is what the old code did.
            if ($source !== null && ! $source->canMoveTo($target)) {
                $rejected[] = $source->label();
                continue;
            }

            $moved = ECRM_REST::transition($id, $target->value, [
                'user_id' => (int) $row['partner_user_id'],
                'from'    => $from,
                'message' => 'Μαζική αλλαγή κατάστασης',
            ]);

            $moved ? $updated++ : $skipped++;
        }

        $response = ['ok' => true, 'updated' => $updated, 'skipped' => $skipped];

        if ($rejected !== []) {
            $response['rejected'] = count($rejected);
            $response['notice']   = sprintf(
                '%d σύμβαση/εις δεν άλλαξαν: δεν επιτρέπεται μετάβαση από «%s» σε «%s».',
                count($rejected),
                implode('», «', array_unique($rejected)),
                $target->label()
            );
        }

        return new WP_REST_Response($response, 200);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function delete(array $rows, UserScope $scope): WP_REST_Response
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        // Bytes before rows: the cascade removes the file records without
        // touching the documents on disk. See FileRepository.
        $this->files->purgeForContracts($ids);

        return new WP_REST_Response(
            ['ok' => true, 'updated' => $this->contracts->deleteMany($ids, $scope)],
            200
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function assign(array $rows, int $newOwner, UserScope $scope): WP_REST_Response
    {
        if ($newOwner <= 0 || ! $scope->includes($newOwner)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Μη επιτρεπτή ανάθεση.'], 403);
        }

        $moved = 0;

        // One at a time through reassign(), which re-checks both the contract
        // and the new owner. Reassignment moves commission, so the extra query
        // per row is worth more than the batch statement it replaces.
        foreach ($rows as $row) {
            if ($this->contracts->reassign((int) $row['id'], $newOwner, $scope)) {
                $moved++;
            }
        }

        return new WP_REST_Response(['ok' => true, 'updated' => $moved], 200);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function export(array $rows, UserScope $scope): WP_REST_Response
    {
        if (! class_exists('ZipArchive')) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Λείπει η επέκταση ZipArchive.'],
                500
            );
        }

        $ids  = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $data = ECRM_Export::contracts_dataset('', '', $ids, $scope->userIds());

        return new WP_REST_Response([
            'ok'       => true,
            'b64'      => base64_encode(ECRM_Export::build_xlsx($data['headers'], $data['rows'])),
            'filename' => 'symvaseis-epilogi-' . gmdate('Ymd-Hi') . '.xlsx',
            'mime'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'count'    => count($data['rows']),
        ], 200);
    }
}
