<?php

/**
 * A contract may not leave draft without the customer's ΑΦΜ — on either door.
 *
 * The rule was asked for as "mandatory ΑΦΜ on finalisation", and the analysis
 * that preceded it (CHANGELOG 2026-08-16 (9)) found the phrase hid two things.
 * A draft has three ways forward, not one — `new`, `pending_signature`,
 * `awaiting_signature` — and two endpoints can take them: POST /contracts,
 * where the form's Οριστικοποίηση button lands, and POST /contracts/{id}/status,
 * where the status screen does. Guarding only the button would have left the
 * status screen able to send an unidentified customer's contract for signature,
 * which is worse than finalising one: the provider's form prints with the ΑΦΜ
 * box empty and goes to the customer that way.
 *
 * So the tests below are arranged by door rather than by scenario, and the two
 * that matter most are 4 and 5 — the second door, and the exception that keeps
 * the bin open.
 *
 * Why the rule exists at all: duplicate detection and search by full ΑΦΜ both
 * run on the `afm_hash` blind index and on nothing else, so a customer stored
 * without an ΑΦΜ can never be flagged as a duplicate. That is 38 of 41 rows on
 * the development database, which is harmless there and would not be in
 * production.
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

final class DraftExitAfmTest extends IntegrationTestCase
{
    private const ROUTE = '/ecrm/v1/contracts';

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

    // --- Door one: POST /contracts, where Οριστικοποίηση lands ---------------

    /** 1. Finalising a draft whose customer has no ΑΦΜ is refused, and moves nothing. */
    public function testFinalisingWithoutAnAfmIsRefused(): void
    {
        $contractId = $this->makeDraft('');

        $response = $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Submitted->value,
        ]);

        self::assertSame(422, $response->get_status(), 'Η οριστικοποίηση χωρίς ΑΦΜ έπρεπε να απορριφθεί.');
        self::assertSame('afm', $response->get_data()['field'], 'Η οθόνη χρειάζεται το πεδίο για να δείξει το μήνυμα.');

        self::assertSame(
            ContractStatus::Draft->value,
            $this->statusOf($contractId),
            'Απορρίφθηκε και όμως προχώρησε.'
        );
    }

    /** 2. The same finalisation, with the ΑΦΜ in the request, goes through. */
    public function testFinalisingWithTheAfmInTheRequestSucceeds(): void
    {
        $contractId = $this->makeDraft('');

        $response = $this->save([
            'contract_id' => $contractId,
            'afm'         => self::VALID_AFM,
            'status'      => ContractStatus::Submitted->value,
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertSame(ContractStatus::Submitted->value, $this->statusOf($contractId));
    }

    /**
     * 3. The ΑΦΜ already stored counts, even when the request does not repeat it.
     *
     * The form resends the whole customer on every save, so this case never
     * arrives from the screen — which is exactly why it needs a test. A refusal
     * here would be a refusal about the shape of the payload rather than about
     * the contract, and only a caller that is not the form would ever meet it.
     */
    public function testAnAfmAlreadyStoredSatisfiesTheGate(): void
    {
        $contractId = $this->makeDraft(self::VALID_AFM);

        $response = $this->save([
            'contract_id' => $contractId,
            'status'      => ContractStatus::Submitted->value,
        ]);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertSame(ContractStatus::Submitted->value, $this->statusOf($contractId));
    }

    /** 4. A contract cannot be created straight into `new` without an ΑΦΜ either. */
    public function testCreatingDirectlyAsSubmittedWithoutAnAfmIsRefused(): void
    {
        $response = $this->save([
            'first_name' => 'Χωρίς',
            'last_name'  => 'ΑΦΜ',
            'status'     => ContractStatus::Submitted->value,
        ]);

        self::assertSame(422, $response->get_status());
        self::assertSame('afm', $response->get_data()['field']);
    }

    // --- Door two: POST /contracts/{id}/status, the status screen ------------

    /**
     * 5. The status screen cannot send an ΑΦΜ-less draft for signature.
     *
     * The one that decides whether this was a rule or a suggestion. Everything
     * above could pass with the check written inline in ContractSaveController,
     * and this route would still be an open door — the shape of the defect
     * CHANGELOG (10) closed for the transition graph, one commit earlier.
     */
    public function testTheStatusRouteAlsoRefusesLeavingDraftWithoutAnAfm(): void
    {
        $contractId = $this->makeDraft('');

        $response = $this->changeStatus($contractId, ContractStatus::PendingSignature->value);

        self::assertSame(422, $response->get_status(), 'Η δεύτερη πόρτα έμεινε ανοιχτή.');
        self::assertSame('afm', $response->get_data()['field']);

        self::assertSame(
            ContractStatus::Draft->value,
            $this->statusOf($contractId),
            'Απορρίφθηκε και όμως στάλθηκε για υπογραφή.'
        );
    }

    /**
     * 6. Cancelling never needs an ΑΦΜ.
     *
     * A draft that cannot be completed is precisely the one that has to be
     * abandonable. A gate that demanded an ΑΦΜ in order to throw work away
     * would trap it on the screen for good.
     */
    public function testCancellingADraftNeedsNoAfm(): void
    {
        $contractId = $this->makeDraft('');

        $response = $this->changeStatus($contractId, ContractStatus::Cancelled->value);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertSame(ContractStatus::Cancelled->value, $this->statusOf($contractId));
    }

    /**
     * 7. Contracts already past draft are not re-judged.
     *
     * The rule guards the exit, not the contract. The 38 rows without an ΑΦΜ
     * that predate it made that move before it existed, and freezing them
     * mid-pipeline would turn a new requirement into an outage.
     */
    public function testAContractAlreadyPastDraftKeepsMovingWithoutAnAfm(): void
    {
        $contractId = $this->makeDraft('');

        $this->forceStatus($contractId, ContractStatus::Submitted->value);

        $response = $this->changeStatus($contractId, ContractStatus::Processing->value);

        self::assertSame(200, $response->get_status(), (string) ($response->get_data()['error'] ?? ''));
        self::assertSame(ContractStatus::Processing->value, $this->statusOf($contractId));
    }

    // --- fixtures and helpers ------------------------------------------------

    /** A draft with a customer, with or without an ΑΦΜ on it. */
    private function makeDraft(string $afm): int
    {
        $payload = [
            'first_name' => 'Δοκιμή',
            'last_name'  => 'Εξόδου',
            'status'     => ContractStatus::Draft->value,
        ];

        if ($afm !== '') {
            $payload['afm'] = $afm;
        }

        $response = $this->save($payload);
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status(), (string) ($data['error'] ?? ''));
        self::assertGreaterThan(0, $data['customer_id'], 'Χωρίς πελάτη το test δεν μετράει τίποτα.');

        return (int) $data['contract_id'];
    }

    private function forceStatus(int $contractId, string $status): void
    {
        global $wpdb;

        $wpdb->update(Tables::name(Tables::CONTRACTS), ['status' => $status], ['id' => $contractId]);

        self::assertSame($status, $this->statusOf($contractId), 'Το fixture δεν γράφτηκε.');
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

    private function changeStatus(int $contractId, string $status): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', self::ROUTE . '/' . $contractId . '/status');
        $request->set_body_params(['status' => $status]);

        return rest_do_request($request);
    }
}
