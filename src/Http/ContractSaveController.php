<?php

/**
 * POST /contracts — create or update a contract together with its customer.
 *
 * The single most dangerous endpoint in the system: it writes customer identity
 * data, it decides who owns the contract, and it is where the IDOR closed in
 * step 2 lived. The ownership rules are therefore not repeated here — every
 * read and write goes through a repository that will not run without a scope.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_Audit;
use ECRM_Validate;
use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractAddresses;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Domain\Contract\ExtraFields;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Domain\Contract\ContractTerm;
use EnergyCRM\Domain\Customer\PostalAddress;
use EnergyCRM\Infrastructure\DocumentQueue;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use WP_REST_Request;
use WP_REST_Response;

final class ContractSaveController implements Controller
{
    /** Customer columns accepted from the request. */
    private const CUSTOMER_FIELDS = [
        'customer_type', 'afm', 'doy', 'first_name', 'last_name', 'father_name',
        'company_name', 'adt', 'birth_date', 'region', 'city', 'street',
        'street_no', 'postal_code', 'phone', 'mobile', 'email',
    ];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly ContractRepository $contracts,
        private readonly CustomerRepository $customers,
        private readonly ContractLifecycle $lifecycle,
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

        $customer = $this->customerFrom($params);

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

        $customerId = $this->resolveCustomer($request, $scope, $existing, $customer);

        if ($customerId === false) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Ο πελάτης δεν βρέθηκε.'], 404);
        }

        $previousCustomer = $customerId > 0 && $customer !== []
            ? $this->customers->find($customerId, $scope)
            : null;

        if ($customer !== []) {
            $customerId = $customerId > 0
                ? ($this->customers->update($customerId, $scope, $customer) ? $customerId : $customerId)
                : $this->customers->create($customer);
        }

        // Whether this is an edit decides how contractFrom() treats a field the
        // request did not send: on create there is no row yet, so every column
        // needs a value and the defaults below are it. On update there is a row
        // already, and a field the agent's screen did not resend must leave
        // that column alone rather than blank it — see contractFrom().
        $isUpdate = $existing !== null;
        $contract = $this->contractFrom($params, $customerId, $isUpdate);

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
     * @param array<string, mixed> $params
     *
     * @return array<string, string>
     */
    private function customerFrom(array $params): array
    {
        $customer = [];

        foreach (self::CUSTOMER_FIELDS as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $customer[$field] = sanitize_text_field((string) $params[$field]);
            }
        }

        return $customer;
    }

    /**
     * The customer this save applies to, or false when the request named one
     * the actor may not touch.
     *
     * @param array<string, mixed>|null $existing
     * @param array<string, string>     $customer
     *
     * @return int|false
     */
    private function resolveCustomer(
        WP_REST_Request $request,
        UserScope $scope,
        ?array $existing,
        array $customer,
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
     * The contract row to write: every column, defaulted, on a fresh save; on
     * an edit, only the columns the request actually sent.
     *
     * ContractRepository::update() already writes exactly the columns present
     * in the array it is given and leaves the rest of the row untouched — the
     * same mechanism customerFrom()/CustomerRepository already relied on. The
     * bug this method used to have was including every column regardless,
     * defaulted to null or a hardcoded value when the request omitted it, which
     * turned "the agent didn't resend price_type" into "price_type is now
     * NULL". Omitting the key on an edit is what makes an omitted field a
     * no-op instead of an erasure.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function contractFrom(array $params, int $customerId, bool $isUpdate): array
    {
        $contract = [];

        // customer_id is never conditionally included: resolveCustomer() has
        // already decided the right value for both create and edit, including
        // "keep what's there" when the request didn't resend it.
        $contract['customer_id'] = $customerId ?: null;

        if (! $isUpdate || isset($params['provider_id'])) {
            $contract['provider_id'] = isset($params['provider_id']) ? (int) $params['provider_id'] : null;
        }

        if (! $isUpdate || isset($params['program_id'])) {
            $contract['program_id'] = isset($params['program_id']) ? (int) $params['program_id'] : null;
        }

        if (! $isUpdate || isset($params['energy_type'])) {
            $contract['energy_type'] = sanitize_text_field((string) ($params['energy_type'] ?? 'power'));
        }

        if (! $isUpdate || isset($params['category'])) {
            $contract['category'] = sanitize_text_field((string) ($params['category'] ?? 'home'));
        }

        if (! $isUpdate || isset($params['price_type'])) {
            $contract['price_type'] = isset($params['price_type'])
                ? sanitize_text_field((string) $params['price_type']) : null;
        }

        if (! $isUpdate || isset($params['customer_type'])) {
            $contract['customer_type'] = sanitize_text_field((string) ($params['customer_type'] ?? 'individual'));
        }

        if (! $isUpdate || isset($params['activation_type'])) {
            $contract['activation_type'] = isset($params['activation_type'])
                ? sanitize_text_field((string) $params['activation_type']) : null;
        }

        if (! $isUpdate || isset($params['supply_number'])) {
            $contract['supply_number'] = isset($params['supply_number'])
                ? sanitize_text_field((string) $params['supply_number']) : null;
        }

        if (! $isUpdate || isset($params['meter_number'])) {
            $contract['meter_number'] = isset($params['meter_number'])
                ? sanitize_text_field((string) $params['meter_number']) : null;
        }

        if (! $isUpdate || isset($params['invoice_code'])) {
            $contract['invoice_code'] = isset($params['invoice_code'])
                ? sanitize_text_field((string) $params['invoice_code']) : null;
        }

        if (! $isUpdate || isset($params['status'])) {
            $status              = ContractStatus::tryFromSlug((string) ($params['status'] ?? ''))
                ?? ContractStatus::Draft;
            $contract['status'] = $status->value;
        }

        if (! $isUpdate || isset($params['notes'])) {
            $contract['notes'] = isset($params['notes'])
                ? sanitize_textarea_field((string) $params['notes']) : null;
        }

        if (! $isUpdate || isset($params['extracted_json'])) {
            $contract['extracted_json'] = isset($params['extracted_json'])
                ? wp_kses_post((string) $params['extracted_json']) : null;
        }

        if (! $isUpdate || isset($params['extra'])) {
            $contract['extra_json'] = ExtraFields::toJson($params['extra'] ?? null);
        }

        // The three are computed together — end_date depends on start_date and
        // term_months — so they are treated as one unit: touch the trio if the
        // request sent any of the three, otherwise leave all three alone.
        if (! $isUpdate
            || isset($params['start_date'])
            || isset($params['term_months'])
            || isset($params['end_date'])
        ) {
            $start  = trim((string) ($params['start_date'] ?? ''));
            $months = (int) ($params['term_months'] ?? 0);

            $contract['start_date']  = $start !== '' ? $start : null;
            $contract['term_months'] = $months > 0 ? $months : null;
            $contract['end_date']    = ContractTerm::endDate(
                $start,
                $months,
                (string) ($params['end_date'] ?? '')
            );
        }

        // Where the meter is, and where the bill goes. Each provider form asks
        // for both and says "εφόσον είναι διαφορετική"; until now the agent
        // typed the meter address into the extras bag and nothing printed it.
        $contract += $this->addressFrom($params, ContractAddresses::SUPPLY_PREFIX, $isUpdate);
        $contract += $this->addressFrom($params, ContractAddresses::BILLING_PREFIX, $isUpdate);

        // GDPR consent: recorded with when and from where, or not at all. Never
        // cleared by omission — consent already given is not un-given by a
        // later save that simply didn't touch the checkbox.
        if (! empty($params['consent'])) {
            $contract['consent_at'] = current_time('mysql');
            $contract['consent_ip'] = substr(
                sanitize_text_field(wp_unslash((string) ($_SERVER['REMOTE_ADDR'] ?? ''))),
                0,
                64
            );
        }

        return $contract;
    }

    /**
     * One of the contract's two extra addresses, read off the request.
     *
     * The "same as home" flag is stored rather than inferred, so a blank
     * address the agent deliberately marked as identical stays distinguishable
     * from one they simply never filled in. When it is set, the parts are
     * cleared too — leaving stale values behind is how a corrected address
     * reappears on the next printed form.
     *
     * The six keys of one address block (the flag plus five parts) are treated
     * as a single unit, the same way the form renders them: on an edit, if the
     * request sent none of the six, the block is left untouched; if it sent
     * any one of them, the whole block is recomputed exactly as it always was.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function addressFrom(array $params, string $prefix, bool $isUpdate): array
    {
        if ($isUpdate && ! $this->addressSent($params, $prefix)) {
            return [];
        }

        $same = ! empty($params[$prefix . 'addr_same']);

        if ($same) {
            return [$prefix . 'addr_same' => 1] + (new PostalAddress())->toColumns($prefix);
        }

        // Only the five address keys are read, and each is scalar by the time
        // it is cast — the request also carries the extras bag, which is an
        // array and must never reach sanitize_text_field().
        $clean = [];

        foreach (['street', 'street_no', 'city', 'postal_code', 'region'] as $part) {
            $value = $params[$prefix . $part] ?? '';

            $clean[$prefix . $part] = is_scalar($value)
                ? sanitize_text_field((string) $value)
                : '';
        }

        return [$prefix . 'addr_same' => 0]
            + PostalAddress::fromRow($clean, $prefix)->toColumns($prefix);
    }

    /**
     * Whether the request touched any of this address block's six keys.
     *
     * @param array<string, mixed> $params
     */
    private function addressSent(array $params, string $prefix): bool
    {
        foreach (['addr_same', 'street', 'street_no', 'city', 'postal_code', 'region'] as $part) {
            if (array_key_exists($prefix . $part, $params)) {
                return true;
            }
        }

        return false;
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
