<?php

/**
 * POST /contracts may not move a contract where the pipeline forbids.
 *
 * ## What these tests were, and what they are now
 *
 * They were written on 2026-08-16 as characterisation, from code reading,
 * before anything ran — and they went green, which was the bad news:
 * ContractSaveMapping::contractFrom() mapped the request's status straight into
 * ContractRepository::update(), where `status` is an ordinary writable column,
 * while the other two routes that write it both ask ContractStatus::canMoveTo()
 * first. A cancelled contract came back to draft. A draft jumped to active,
 * which is payable. CHANGELOG 2026-08-16 (9) has the measurement.
 *
 * They are now regression tests for the fix, and their assertions were
 * deliberately inverted. That is allowed here and is the point: this commit
 * changes behaviour on purpose. It is not the class-splitting rule, under which
 * a changed assertion means the split went wrong.
 *
 * ## What is deliberately still permitted
 *
 * Two of the five below assert that something keeps working, and they matter as
 * much as the three refusals. Finalising a draft is the single busiest action
 * in the product, and editing a field on a contract past draft is not a
 * transition at all — a guard that stopped either would be a worse bug than the
 * one it replaced, and would look like success from the refusal tests alone.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Persistence\Tables;
use WP_REST_Request;
use WP_REST_Response;

final class ContractSaveStatusTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/contracts';

    /** Passes the check-digit test, so a mapping result is never masked by a 422. */
    private const VALID_AFM = '090003373';

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user($this->makeCrmUser(Roles::SELLER));
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * 1. A cancelled contract cannot be brought back to life.
     *
     * Cancelled->allowedNext() is the empty array, so this is the sharpest of
     * the three refusals: there is no reading of the graph under which it is
     * permitted, and no screen that ever wanted it.
     */
    public function testACancelledContractCannotBeMovedBackToDraft(): void
    {
        $contractId = $this->makeDraft();

        $this->forceStatus($contractId, ContractStatus::Cancelled->value);

        $response = $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Draft->value,
        ]);

        self::assertSame(409, $response->get_status(), 'Η ανάσταση ακυρωμένης σύμβασης έπρεπε να απορριφθεί.');

        self::assertSame(
            ContractStatus::Cancelled->value,
            $this->statusOf($contractId),
            'Η απόρριψη απάντησε 409 αλλά η γραμμή άλλαξε — άρνηση που γράφει είναι χειρότερη από καθόλου άρνηση.'
        );

        $data = $response->get_data();

        self::assertFalse($data['ok']);
        self::assertSame(
            [],
            $data['allowed'],
            'Από τερματική κατάσταση δεν υπάρχει επιτρεπτή μετάβαση, και η απάντηση πρέπει να το λέει.'
        );
    }

    /**
     * 2. A draft cannot jump the queue into a payable status.
     *
     * Draft->allowedNext() is [new, pending_signature, awaiting_signature,
     * cancelled]. Active is not among them, and it is what makes a contract
     * count for commission — which is why this one is about money rather than
     * tidiness.
     */
    public function testADraftCannotBeMovedStraightToPayableActive(): void
    {
        $contractId = $this->makeDraft();

        $response = $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Active->value,
        ]);

        self::assertSame(409, $response->get_status(), 'Το draft → active έπρεπε να απορριφθεί.');

        self::assertSame(
            ContractStatus::Draft->value,
            $this->statusOf($contractId),
            'Η σύμβαση μετακινήθηκε παρά την απόρριψη.'
        );

        $allowed = array_column($response->get_data()['allowed'], 'status');

        self::assertNotContains(ContractStatus::Active->value, $allowed);
        self::assertContains(
            ContractStatus::Submitted->value,
            $allowed,
            'Η λίστα των επιτρεπτών πρέπει να προτείνει τη μετάβαση που ο συνεργάτης όντως ήθελε.'
        );
    }

    /**
     * 3. A contract cannot be born payable, and a refused save writes nothing.
     *
     * Creation has no previous status, so the graph cannot judge it — this is a
     * separate rule, not a canMoveTo() check. The second half of the test is
     * the more important one: the guard runs before the customer is written, so
     * a refusal leaves the database exactly as it was. A 422 that had already
     * created a customer would be a half-applied request, which is the shape of
     * bug this file exists to stop.
     */
    public function testANewContractCannotBeCreatedInAPayableStatusAndNothingIsWritten(): void
    {
        $contractsBefore = $this->countRows(Tables::CONTRACTS);
        $customersBefore = $this->countRows(Tables::CUSTOMERS);

        $response = $this->save([
            'first_name' => 'Δοκιμή',
            'last_name'  => 'Καταστάσεων',
            'afm'        => self::VALID_AFM,
            'status'     => ContractStatus::Active->value,
        ]);

        self::assertSame(422, $response->get_status(), 'Η δημιουργία σε προμηθεύσιμη κατάσταση έπρεπε να απορριφθεί.');
        self::assertSame('status', $response->get_data()['field']);

        self::assertSame(
            $contractsBefore,
            $this->countRows(Tables::CONTRACTS),
            'Απορρίφθηκε και όμως γράφτηκε σύμβαση.'
        );

        self::assertSame(
            $customersBefore,
            $this->countRows(Tables::CUSTOMERS),
            'Απορρίφθηκε και όμως γράφτηκε πελάτης — ο φύλακας τρέχει πολύ αργά μέσα στη save().'
        );
    }

    /**
     * 4. Finalising a draft still works. The busiest action in the product.
     *
     * Draft → new is the one transition the finalise button asks for, and a
     * guard that broke it would be caught by no refusal test — every one of
     * those would still be green.
     */
    public function testFinalisingADraftStillSucceeds(): void
    {
        $contractId = $this->makeDraft();

        $response = $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Submitted->value,
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        self::assertSame(
            ContractStatus::Submitted->value,
            $this->statusOf($contractId),
            'Η οριστικοποίηση δεν προχώρησε — ο φύλακας έπιασε μετάβαση που ο γράφος επιτρέπει.'
        );
    }

    /**
     * 5. Editing a field on a contract past draft is not a transition.
     *
     * This is the path behind the third button on the form: a payload with no
     * `status` key at all, which contractFrom() answers by omitting the column.
     * Without it the fix would leave every finalised contract uneditable, and
     * the agent with no way to correct a typo.
     */
    public function testEditingFieldsWithoutSendingStatusIsAllowedAtAnyStage(): void
    {
        $contractId = $this->makeDraft();

        $this->forceStatus($contractId, ContractStatus::Signed->value);

        $response = $this->save([
            'contract_id' => $contractId,
            'notes'       => 'Διόρθωση μετά την υπογραφή',
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));

        $row = $this->storedRow('contracts', $contractId);

        self::assertSame('Διόρθωση μετά την υπογραφή', $row['notes'], 'Η επεξεργασία πεδίου δεν πέρασε.');

        self::assertSame(
            ContractStatus::Signed->value,
            $row['status'],
            'Αίτημα χωρίς status μετακίνησε την κατάσταση — η παράλειψη πρέπει να είναι no-op.'
        );
    }

    // --- fixtures and helpers ------------------------------------------------

    /** A saved draft, through the same route under test. */
    private function makeDraft(): int
    {
        $response = $this->save([
            'first_name' => 'Δοκιμή',
            'last_name'  => 'Καταστάσεων',
            'afm'        => self::VALID_AFM,
            'status'     => ContractStatus::Draft->value,
        ]);

        $data = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));
        self::assertGreaterThan(0, $data['contract_id'], 'Το draft fixture δεν αποθηκεύτηκε.');

        return (int) $data['contract_id'];
    }

    /** Put a row into a status directly, as a fixture — never as an assertion. */
    private function forceStatus(int $contractId, string $status): void
    {
        global $wpdb;

        $wpdb->update(Tables::name(Tables::CONTRACTS), ['status' => $status], ['id' => $contractId]);

        self::assertSame(
            $status,
            $this->statusOf($contractId),
            'Το fixture δεν γράφτηκε. Ένα test που ξεκινά από λάθος κατάσταση δεν '
            . 'μετράει αυτό που νομίζει — δες HANDOVER, «μια κλήση δεν είναι κάλυψη».'
        );
    }

    private function countRows(string $unprefixedTable): int
    {
        global $wpdb;

        $table = Tables::name($unprefixedTable);

        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    private function statusOf(int $contractId): string
    {
        return (string) $this->storedRow('contracts', $contractId)['status'];
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
