<?php

/**
 * POST /contracts — create or update a contract together with its customer.
 *
 * The single most dangerous endpoint in the system: it writes customer identity
 * data, it decides who owns the contract, and it is where the IDOR closed in
 * step 2 lived. The ownership rules are therefore not repeated here — every
 * read and write goes through a repository that will not run without a scope.
 *
 * What is left here is the orchestration: resolve the target, refuse what the
 * actor may not touch, write, audit, enqueue. Turning the request into columns
 * moved to ContractSaveMapping — 220 lines that change when a provider adds a
 * field, next to code that changes when an ownership rule does.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Audit;
use ECRM_Validate;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Infrastructure\DocumentQueue;
use EnergyCRM\Infrastructure\DraftExitGate;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ContractSaveController implements Controller
{
    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly CustomerRepository $customers,
        private readonly ContractLifecycle $lifecycle,
        private readonly DraftExitGate $draftExit,
    ) {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/contracts', [
            'methods'             => 'POST',
            'callback'            => [$this, 'save'],
            'permission_callback' => Guards::crmUser(),
            // Field-level shapes are declared where they are read; what matters
            // at the route is that the two ids are integers, because they are
            // what decides which rows get written.
            'args'                => [
                'contract_id' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                'customer_id' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
            ],
        ]);
    }

    public function save(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_json_params() ?: $request->get_params();
        $scope  = $this->scopes->forCurrentUser();

        // Resolve the target before touching anything: a contract the actor
        // cannot see is indistinguishable from one that does not exist.
        $contractId = (int) $request['contract_id'];
        $existing   = null;

        if ($contractId > 0) {
            $existing = $this->contracts->find($contractId, $scope);

            if ($existing === null) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Η σύμβαση δεν βρέθηκε.'], 404);
            }
        }

        $customer = ContractSaveMapping::customerFrom($params);

        if (isset($customer['afm']) && ! ECRM_Validate::afm($customer['afm'])) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Μη έγκυρο ΑΦΜ (αποτυχία ελέγχου ψηφίου).',
                'field' => 'afm',
            ], 422);
        }

        // The address is not required — customerFrom() drops empty fields, so
        // reaching this line means one was typed. Refused rather than stored,
        // because the two places it goes are both silent about it: it prints
        // onto the provider's form as though it were an address, and
        // SignLinkController answers `emailed: false` when is_email() rejects
        // it, so the agent sees a saved contract and the customer never gets
        // the signature link. A save that stops with a message is the only
        // version of this the agent finds out about.
        if (isset($customer['email']) && ! is_email($customer['email'])) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => 'Μη έγκυρη διεύθυνση email. Διόρθωσέ την ή άφησέ την κενή.',
                'field' => 'email',
            ], 422);
        }

        $customerId = $this->resolveCustomer($request, $scope, $existing);

        if ($customerId === false) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Ο πελάτης δεν βρέθηκε.'], 404);
        }

        // Before anything is written, including the customer row: a refused
        // save must leave the whole request without effect. Putting this after
        // the customer update would answer 409 having already changed the
        // customer's name. resolveCustomer() above only reads, so it is safe on
        // this side of the line — and the gate needs the id it resolves.
        $refusal = $this->refuseStatusChange($params, $existing, $customerId, $scope, $customer);

        if ($refusal !== null) {
            return $refusal;
        }

        $previousCustomer = $customerId > 0 && $customer !== []
            ? $this->customers->find($customerId, $scope)
            : null;

        if ($customer !== []) {
            if ($customerId > 0) {
                // The bool is dropped on purpose: what stood here returned
                // $customerId in both branches of a ternary, which reads as a
                // handled failure and never was one — CHANGELOG 2026-08-16 (7).
                $this->customers->update($customerId, $scope, $customer);
            } else {
                $customerId = $this->customers->create($customer);
            }
        }

        // Whether this is an edit decides how contractFrom() treats a field the
        // request did not send: on create there is no row yet, so every column
        // needs a value and the defaults below are it. On update there is a row
        // already, and a field the agent's screen did not resend must leave
        // that column alone rather than blank it — see contractFrom().
        $isUpdate = $existing !== null;
        $contract = ContractSaveMapping::contractFrom($params, $customerId, $isUpdate);

        if ($isUpdate) {
            $this->contracts->update($contractId, $scope, $contract);
        } else {
            $contractId = $this->contracts->create($contract, $scope);

            if ($contractId <= 0) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Η αποθήκευση απέτυχε.'], 500);
            }

            $this->contracts->assignCode($contractId, $scope);
        }

        $this->recordHistory($contractId, $scope, $existing, $previousCustomer, $contract, $customer);

        // Scheduled, not rendered here. Building it inline held a PHP worker
        // and 256 MB for seconds on every save, drafts included — see
        // DocumentQueue. Nothing on this screen waits for the file.
        DocumentQueue::enqueue($contractId);

        // $contract['status'] is only absent when this was an edit that did
        // not resend status — the column, and therefore $existing, are both
        // guaranteed to exist in that case.
        $responseStatus = $contract['status'] ?? ($existing !== null ? $existing['status'] : null);

        return new WP_REST_Response([
            'ok'          => true,
            'contract_id' => $contractId,
            'customer_id' => $customerId,
            'status'      => $responseStatus,
        ], 200);
    }

    /**
     * Refuse a status the pipeline does not allow from here, or null to proceed.
     *
     * Until 2026-08-16 this route was the only one of the three that writes
     * `status` without consulting anything: ContractStatusController::change()
     * and ContractsBulkController::changeStatus() both ask
     * ContractStatus::canMoveTo() first. That was not a small inconsistency,
     * because finalisation happens here and nowhere else — ecrm-form.js binds
     * [data-finalize] to a POST on this route — and because routed, active and
     * resolved are payable. ContractSaveStatusTest pinned what it allowed:
     * reviving a cancelled contract, and jumping a draft straight to active.
     *
     * The rule itself is not repeated here. It lives in the enum, and this asks
     * the enum the same question ContractStatusController asks, answering with
     * the same shape so one screen can handle both.
     *
     * A request that does not send `status` is not a transition and is not
     * touched: contractFrom() omits the column entirely, which is what makes an
     * ordinary field edit on a signed contract still work.
     *
     * Two refusals, in this order. The graph decides whether the move exists at
     * all, and answers 409. Only then does DraftExitGate ask whether this
     * particular contract is ready to make it, and answers 422 — a legal move
     * the contract is not ready for is a different thing from an illegal one,
     * and the agent fixes them differently. The gate is a shared object rather
     * than an `if` here because ContractStatusController can make the same
     * moves; a rule enforced on one of two doors is the shape of the bug
     * CHANGELOG (10) had just closed.
     *
     * @param array<string, mixed>      $params
     * @param array<string, mixed>|null $existing
     * @param array<string, string>     $customer
     */
    private function refuseStatusChange(
        array $params,
        ?array $existing,
        int $customerId,
        UserScope $scope,
        array $customer,
    ): ?WP_REST_Response {
        if (! isset($params['status'])) {
            return null;
        }

        $target = ContractSaveMapping::statusFrom($params);
        $afm    = (string) ($customer['afm'] ?? '');

        // Creation has no previous status, so the graph has nothing to say
        // about it — but "every contract starts as a draft" was a property of
        // the screen rather than of this endpoint, and a contract could be born
        // payable. These two are the only stages a first save can mean.
        if ($existing === null) {
            if ($target !== ContractStatus::Draft && $target !== ContractStatus::Submitted) {
                return new WP_REST_Response([
                    'ok'    => false,
                    'error' => 'Νέα σύμβαση μπορεί να ξεκινήσει μόνο ως πρόχειρη ή ως νέα αίτηση.',
                    'field' => 'status',
                ], 422);
            }

            return $this->refuseMissingAfm(
                $this->draftExit->refusalOnCreate($target, $customerId, $scope, $afm)
            );
        }

        $source = ContractStatus::tryFromSlug((string) $existing['status']);

        if ($source === null) {
            return null;
        }

        if ($source->canMoveTo($target)) {
            return $this->refuseMissingAfm(
                $this->draftExit->refusalOnMove($source, $target, $customerId, $scope, $afm)
            );
        }

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

    /**
     * Wrap the gate's answer, or pass null straight through.
     *
     * 422 rather than 409: the transition itself is legal, and the agent fixes
     * this by typing an ΑΦΜ rather than by choosing a different stage. `field`
     * is what the form uses to put the message on the right input, the same way
     * the ΑΦΜ and email validators above already do.
     */
    private function refuseMissingAfm(?string $refusal): ?WP_REST_Response
    {
        if ($refusal === null) {
            return null;
        }

        return new WP_REST_Response(['ok' => false, 'error' => $refusal, 'field' => 'afm'], 422);
    }

    /**
     * The customer this save applies to, or false when the request named one
     * the actor may not touch.
     *
     * @param array<string, mixed>|null $existing
     *
     * @return int|false
     */
    private function resolveCustomer(
        WP_REST_Request $request,
        UserScope $scope,
        ?array $existing,
    ): int|false {
        $customerId = (int) $request['customer_id'];

        if ($customerId <= 0) {
            // No customer named in the request. On a fresh save that means
            // "none yet" — 0 is correct. On an edit it means the screen did
            // not resend it, and the contract already has one: keep it. This
            // used to return 0 unconditionally, which is how customer_id
            // became NULL on every edit that did not resend it (CHANGELOG
            // 2026-08-16 (3)/(4)).
            return $existing !== null ? (int) ($existing['customer_id'] ?? 0) : 0;
        }

        // Honoured when already attached to the contract being edited, or
        // otherwise reachable through one of the actor's contracts.
        $attached = $existing !== null && (int) $existing['customer_id'] === $customerId;

        if (! $attached && ! $this->customers->isReachable($customerId, $scope)) {
            return false;
        }

        return $customerId;
    }

    /**
     * Field-level diff on edits, a creation event on first save.
     *
     * @param array<string, mixed>|null $existing
     * @param array<string, mixed>|null $previousCustomer
     * @param array<string, mixed>      $contract
     * @param array<string, string>     $customer
     */
    private function recordHistory(
        int $contractId,
        UserScope $scope,
        ?array $existing,
        ?array $previousCustomer,
        array $contract,
        array $customer,
    ): void {
        if ($existing === null) {
            $this->lifecycle->logCreation($contractId, $scope->actorId(), (string) $contract['status']);

            return;
        }

        $changes = [];

        if ($previousCustomer !== null) {
            $changes += ECRM_Audit::diff($previousCustomer, $customer);
        }

        // $contract now holds only the columns the request actually sent, so
        // the diff against $existing already reflects exactly what changed —
        // no different from before, except it is no longer a diff against a
        // row full of defaults the agent never asked for.
        $changes += ECRM_Audit::diff($existing, $contract);

        ECRM_Audit::log($contractId, $changes);
    }
}
