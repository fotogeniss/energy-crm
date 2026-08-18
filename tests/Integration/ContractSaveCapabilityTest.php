<?php

/**
 * Which capability POST /contracts asks for, and when.
 *
 * The route serves three different acts through one URL — create a contract,
 * edit its fields, move it along the pipeline — and until now asked for none of
 * them: `Guards::crmUser()` let anyone with a CRM role do all three. The very
 * same move through POST /contracts/{id}/status has always required
 * CHANGE_STATUS. Two doors onto one act, and one of them unlocked; audit
 * finding 20.2.
 *
 * ## Why these users are built by hand
 *
 * Not one of the three roles is missing any of these three capabilities today,
 * so against the current matrix every assertion below would pass with the old
 * code too. That is exactly why the finding was ΧΑΜΗΛΟ and not higher — and
 * exactly why a test written against the matrix would guard nothing. The users
 * here have the capability removed individually, which is the shape the matrix
 * takes the day the company decides a Καταχωρητής types applications but does
 * not finalise them. The rule is what is pinned, not today's table.
 *
 * ## The one that is not a transition
 *
 * ecrm-form.js sends `status: 'draft'` on every "Προσωρινή αποθήκευση" of a
 * draft — the same status the row already has. If that counted as a move, the
 * capability would silently take away the save button from a role that is
 * supposed to keep it, and the first anyone would hear of it is a 403 on a
 * screen that had worked the day before.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Capability;
use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\ContractStatus;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

final class ContractSaveCapabilityTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/contracts';

    /** Passes the check-digit test, so nothing here is masked by a 422. */
    private const VALID_AFM = '090003373';

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    // --- the paths that must keep working ---------------------------------

    /** A seller with the full matrix finalises a draft, exactly as before. */
    public function testTheOrdinaryRoleStillFinalisesADraft(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $contractId = $this->makeDraft();

        $response = $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Submitted->value,
        ]);

        self::assertSame(200, $response->get_status(), $this->errorOf($response));
    }

    /**
     * Saving a draft again is not a move, so it needs no permission to move.
     *
     * The status in the payload equals the one on the row. Reading that as a
     * transition would break the button ecrm-form.js presses most often.
     */
    public function testResendingTheSameStatusIsNotATransition(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $contractId = $this->makeDraft();

        $this->revoke(Capability::CHANGE_STATUS);

        $response = $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Draft->value,
            'last_name'   => 'Αλλαγμένο',
        ]);

        self::assertSame(200, $response->get_status(), $this->errorOf($response));
    }

    /** A field edit that sends no status never asks about moving. */
    public function testAFieldEditWithoutAStatusDoesNotNeedTheStatusCapability(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $contractId = $this->makeDraft();

        $this->revoke(Capability::CHANGE_STATUS);

        $response = $this->save([
            'contract_id' => $contractId,
            'last_name'   => 'Αλλαγμένο',
        ]);

        self::assertSame(200, $response->get_status(), $this->errorOf($response));
    }

    // --- the three refusals ------------------------------------------------

    /** Finalising is a move, and a move needs CHANGE_STATUS. */
    public function testFinalisingIsRefusedWithoutTheStatusCapability(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $contractId = $this->makeDraft();

        $this->revoke(Capability::CHANGE_STATUS);

        $response = $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Submitted->value,
        ]);

        self::assertSame(403, $response->get_status());
    }

    /** And the refusal leaves the row where it was. */
    public function testTheRefusedFinalisationDoesNotMoveTheRow(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $contractId = $this->makeDraft();

        $this->revoke(Capability::CHANGE_STATUS);

        $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Submitted->value,
        ]);

        self::assertSame(
            ContractStatus::Draft->value,
            (string) $this->storedRow('contracts', $contractId)['status']
        );
    }

    /** Creating a contract needs CREATE_CONTRACT. */
    public function testCreationIsRefusedWithoutTheCreateCapability(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $this->revoke(Capability::CREATE_CONTRACT);

        $response = $this->save([
            'first_name' => 'Δοκιμή',
            'last_name'  => 'Δικαιωμάτων',
            'afm'        => self::VALID_AFM,
            'status'     => ContractStatus::Draft->value,
        ]);

        self::assertSame(403, $response->get_status());
    }

    /** Editing an existing contract needs EDIT_CONTRACT. */
    public function testEditingIsRefusedWithoutTheEditCapability(): void
    {
        wp_set_current_user($this->makeCrmUser(Roles::SELLER));

        $contractId = $this->makeDraft();

        $this->revoke(Capability::EDIT_CONTRACT);

        $response = $this->save([
            'contract_id' => $contractId,
            'last_name'   => 'Αλλαγμένο',
        ]);

        self::assertSame(403, $response->get_status());
    }

    // --- fixtures ----------------------------------------------------------

    /**
     * Take one capability away from the current user, leaving the role intact.
     *
     * Per-user rather than per-role: the role object is shared by every test in
     * the process, and WordPress persists it. Removing a capability there would
     * leak into whatever runs next.
     */
    private function revoke(string $capability): void
    {
        $user = wp_get_current_user();

        self::assertInstanceOf(WP_User::class, $user);

        $user->add_cap($capability, false);
    }

    private function makeDraft(): int
    {
        $response = $this->save([
            'first_name' => 'Δοκιμή',
            'last_name'  => 'Δικαιωμάτων',
            'afm'        => self::VALID_AFM,
            'status'     => ContractStatus::Draft->value,
        ]);

        $data = $response->get_data();

        self::assertSame(200, $response->get_status(), $this->errorOf($response));
        self::assertGreaterThan(0, $data['contract_id'], 'Το draft fixture δεν αποθηκεύτηκε.');

        return (int) $data['contract_id'];
    }

    /** The message behind a failure, so a red test says why rather than what. */
    private function errorOf(WP_REST_Response $response): string
    {
        $data = $response->get_data();

        return (string) ($data['error'] ?? '');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function save(array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::ROUTE);
        $request->set_body_params($params);

        return rest_do_request($request);
    }
}
