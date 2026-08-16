<?php

/**
 * Whether POST /contracts is allowed to move a contract anywhere in the pipeline.
 *
 * ## Why this file exists
 *
 * ContractStatus documents two promises in its own docblock: terminal states
 * are terminal, and nothing returns to a stage before its signature. Two of the
 * three routes that write the status column enforce them —
 * ContractStatusController::change() answers 409, and
 * ContractsBulkController::changeStatus() counts the row as rejected. Both go
 * through ContractLifecycle::moveTo(), which refuses a disallowed move and
 * records a status_change event.
 *
 * The third route is this one. Reading it line by line says it enforces
 * nothing: ContractSaveMapping::contractFrom() maps the request's status
 * through tryFromSlug() and hands it to ContractRepository::update(), where
 * `status` is an ordinary writable column. No canMoveTo(). No documents gate.
 * No moveTo() at all.
 *
 * That matters beyond tidiness, because finalisation happens here and nowhere
 * else — ecrm-form.js:972 binds [data-finalize] to save('new'), which is a
 * POST to this route — and because ContractStatus::isPayable() returns true for
 * routed, active and resolved. If the reading is right, an agent can put their
 * own contract into a payable state without passing any gate.
 *
 * ## These tests are characterisation, and the answer is not known yet
 *
 * They were written from code reading, before anything was run, and they assert
 * the behaviour that reading predicts. Both outcomes are meaningful, so read
 * the result rather than the colour:
 *
 * - **Green** confirms the bypass is real. The suite going green is then
 *   evidence of a defect, not of health — exactly what happened to test 2 of
 *   ContractSaveMappingTest on 2026-08-16, whose title said the opposite of
 *   what its green meant.
 * - **Red** means a guard exists that the reading missed. That is the better
 *   news of the two: nothing is broken, and these tests should be rewritten as
 *   regression tests asserting the refusal, keeping the fixtures.
 *
 * Nothing should be changed in src/ until one of the two is on screen. Each
 * assertion below says, in its failure message, what its own failure would
 * mean.
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
     * 1. A cancelled contract, edited back into draft.
     *
     * The strongest of the three, because cancelled is terminal:
     * ContractStatus::Cancelled->allowedNext() is the empty array, and
     * canMoveTo() therefore permits nothing but staying put. There is no
     * legitimate screen for this move — unlike test 2, which has one.
     *
     * The row is forced to cancelled with a direct write rather than through
     * the status route, so that the test depends on one controller instead of
     * two: what is under examination is the save route, not how the contract
     * arrived where it is.
     */
    public function testACancelledContractCanBeMovedBackToDraftThroughSave(): void
    {
        $contractId = $this->makeDraft();

        $this->forceStatus($contractId, ContractStatus::Cancelled->value);

        $response = $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Draft->value,
        ]);

        self::assertSame(200, $response->get_status(), 'Η αποθήκευση δεν πέρασε καν.');

        self::assertSame(
            ContractStatus::Draft->value,
            $this->statusOf($contractId),
            'ΑΝ ΑΥΤΟ ΕΙΝΑΙ ΚΟΚΚΙΝΟ: υπάρχει φύλακας που η ανάλυση δεν είδε, και η '
            . 'ακυρωμένη σύμβαση δεν αναστήθηκε — καλά νέα. Ξαναγράψε το test ως '
            . 'regression της άρνησης. ΑΝ ΕΙΝΑΙ ΠΡΑΣΙΝΟ: η υπόσχεση «τα τερματικά '
            . 'είναι τερματικά» του ContractStatus παρακάμπτεται από το POST /contracts.'
        );
    }

    /**
     * 2. A draft, edited straight into active — past six intermediate stages.
     *
     * Draft->allowedNext() is [new, pending_signature, awaiting_signature,
     * cancelled]. Active is not among them, and it is payable, which is what
     * makes this the expensive one rather than merely the untidy one.
     *
     * The documents gate guards active too, in the two routes that check it.
     * This test deliberately does NOT assert anything about that gate: whether
     * ECRM_Docs::missing_labels() would have found anything for this fixture
     * depends on provider document configuration that the fixture does not set
     * up, and an assertion that cannot fail proves nothing. The transition
     * graph is enough to make the point, and it needs no configuration.
     */
    public function testADraftCanBeMovedStraightToPayableActiveThroughSave(): void
    {
        $contractId = $this->makeDraft();

        $response = $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Active->value,
        ]);

        self::assertSame(200, $response->get_status(), 'Η αποθήκευση δεν πέρασε καν.');

        $stored = $this->statusOf($contractId);

        self::assertSame(
            ContractStatus::Active->value,
            $stored,
            'ΑΝ ΚΟΚΚΙΝΟ: ο γράφος εφαρμόζεται και εδώ, η ανάλυση ήταν λάθος. '
            . 'ΑΝ ΠΡΑΣΙΝΟ: draft → active χωρίς κανένα από τα έξι ενδιάμεσα στάδια.'
        );

        self::assertTrue(
            ContractStatus::from($stored)->isPayable(),
            'Η κατάσταση που προσγειώθηκε δεν είναι προμηθεύσιμη — τότε αυτό το test '
            . 'μετράει κάτι λιγότερο σοβαρό από όσο λέει ο τίτλος του.'
        );
    }

    /**
     * 3. A contract born active, never having been a draft.
     *
     * The create path has no previous status, so the transition graph has
     * nothing to say about it — this is not a bypass of canMoveTo(), and it is
     * not written here as one. What it pins is narrower and still worth
     * pinning: the status a fresh contract lands in is whatever the request
     * asked for, so "every contract starts as a draft" is a property of the
     * screen, not of the endpoint.
     */
    public function testANewContractCanBeCreatedDirectlyInAPayableStatus(): void
    {
        $response = $this->save([
            'first_name' => 'Δοκιμή',
            'last_name'  => 'Καταστάσεων',
            'afm'        => self::VALID_AFM,
            'status'     => ContractStatus::Active->value,
        ]);

        $data = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));

        self::assertSame(
            ContractStatus::Active->value,
            $this->statusOf((int) $data['contract_id']),
            'ΑΝ ΚΟΚΚΙΝΟ: η δημιουργία περιορίζει την αρχική κατάσταση, και η ανάλυση '
            . 'ήταν λάθος. ΑΝ ΠΡΑΣΙΝΟ: μια σύμβαση μπορεί να γεννηθεί προμηθεύσιμη.'
        );
    }

    // --- fixtures and helpers ------------------------------------------------

    /**
     * A saved draft, through the same route under test.
     *
     * Created through the endpoint rather than inserted, so that every column
     * the mapping fills is filled the way production fills it. The status of
     * the fixture is then forced directly where a test needs a different one.
     */
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
