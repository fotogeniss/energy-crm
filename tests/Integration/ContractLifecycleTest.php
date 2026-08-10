<?php

/**
 * What `transition()` actually does, written down before it moves.
 *
 * This is a characterisation suite, not a specification. Every assertion here
 * describes the behaviour as it is today, deliberately — including the corners
 * that look odd — because the next step of the roadmap lifts this logic out of
 * ECRM_REST and into src/, and the only safe way to move code is to be able to
 * prove afterwards that it still behaves the same.
 *
 * It earns its place regardless of that move. `transition()` is the single
 * riskiest function in the plugin: it writes the status, appends to the event
 * log, fires the in-app notification, fires the SMS, and schedules a cron job —
 * and every signature in the system passes through it. None of that was covered
 * by anything.
 *
 * Two things make it safe to run: SMS is off unless `sms_enabled` is '1', which
 * no test sets, and the in-app notification only fires for `pending`, where the
 * test that goes there blocks wp_mail explicitly.
 *
 * Authorisation is not tested here because it does not live here. `transition()`
 * takes a raw id and trusts its caller; the controllers resolve the contract
 * through a scoped repository first. That is checked in ContractRestAccessTest.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_REST;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;

final class ContractLifecycleTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private int $partner;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->partner   = $this->makePartner();

        $this->contractId = $this->contracts->create(
            ['status' => 'new', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $this->contractId);
    }

    public function testAPermittedMoveIsApplied(): void
    {
        self::assertTrue(ECRM_REST::transition($this->contractId, 'processing'));
        self::assertSame('processing', $this->statusOnDisk());
    }

    /** An unknown slug is refused before anything is written. */
    public function testAStatusThatDoesNotExistChangesNothing(): void
    {
        self::assertFalse(ECRM_REST::transition($this->contractId, 'not_a_status'));

        self::assertSame('new', $this->statusOnDisk());
        self::assertSame([], $this->eventsFor($this->contractId));
    }

    /**
     * The pipeline graph is enforced here, not only in the controller.
     *
     * Cancelled is terminal. If this ever passes, a cancelled contract can be
     * brought back to life by any caller that skips the controller.
     */
    public function testAMoveThePipelineForbidsIsRefused(): void
    {
        ECRM_REST::transition($this->contractId, 'cancelled');

        self::assertFalse(ECRM_REST::transition($this->contractId, 'signed'));
        self::assertSame('cancelled', $this->statusOnDisk());
    }

    /**
     * Asking for the status it already has succeeds and writes nothing.
     *
     * True rather than false on purpose: the caller asked for a state, and the
     * contract is in that state. But no event is logged, because nothing
     * happened — a log full of "new → new" would bury the real history.
     */
    public function testMovingToTheStatusItAlreadyHasSucceedsSilently(): void
    {
        self::assertTrue(ECRM_REST::transition($this->contractId, 'new'));
        self::assertSame([], $this->eventsFor($this->contractId));
    }

    /** `force` is how a caller says "log it anyway", e.g. to re-run side effects. */
    public function testForceWritesTheEventEvenWithoutAChange(): void
    {
        self::assertTrue(ECRM_REST::transition($this->contractId, 'new', ['force' => true]));
        self::assertCount(1, $this->eventsFor($this->contractId));
    }

    public function testTheEventRecordsWhoMovedItAndFromWhere(): void
    {
        ECRM_REST::transition($this->contractId, 'processing', [
            'user_id' => $this->partner,
            'message' => 'Χειροκίνητη μετάβαση',
        ]);

        $events = $this->eventsFor($this->contractId);

        self::assertCount(1, $events);
        self::assertSame('status_change', $events[0]['type']);
        self::assertSame($this->partner, (int) $events[0]['user_id']);
        self::assertSame('new', $events[0]['from_status']);
        self::assertSame('processing', $events[0]['to_status']);
        self::assertSame('Χειροκίνητη μετάβαση', $events[0]['message']);
    }

    /**
     * An empty origin is stored as NULL, not as ''.
     *
     * Callers that genuinely do not know the previous status pass `from => null`
     * — the signing routes do exactly this. NULL says "unknown"; an empty string
     * would read as a status that does not exist.
     */
    public function testAnUnknownOriginIsStoredAsNull(): void
    {
        ECRM_REST::transition($this->contractId, 'processing', ['from' => null]);

        self::assertNull($this->eventsFor($this->contractId)[0]['from_status']);
    }

    /** `extra` is how the signature audit columns are written in the same UPDATE. */
    public function testExtraColumnsAreWrittenAlongsideTheStatus(): void
    {
        ECRM_REST::transition($this->contractId, 'signed', [
            'extra' => ['signed_ip' => '198.51.100.7'],
        ]);

        $row = $this->storedRow('contracts', $this->contractId);

        self::assertSame('signed', $row['status']);
        self::assertSame('198.51.100.7', $row['signed_ip']);
    }

    /**
     * Signing schedules its own follow-up.
     *
     * The contract id is passed as an argument so that several signatures in the
     * same window each get their own event — WordPress de-duplicates identical
     * no-argument events, and without the id all but the first would vanish.
     */
    public function testSigningSchedulesTheAutomaticMoveToProcessing(): void
    {
        ECRM_REST::transition($this->contractId, 'signed');

        self::assertIsInt(
            wp_next_scheduled(ECRM_REST::AUTO_PROCESS_HOOK, [$this->contractId]),
            'Nothing will ever move this contract on by itself.'
        );
    }

    public function testTheDelayBeforeAutomaticProcessingIsFilterable(): void
    {
        add_filter('ecrm_auto_process_delay', static fn (): int => 42);

        self::assertSame(42, ECRM_REST::auto_process_delay());

        remove_all_filters('ecrm_auto_process_delay');
    }

    /**
     * The sweep promotes a contract signed long enough ago.
     *
     * signed_at is written in site-local time by current_time('mysql'), and the
     * cutoff is computed the same way. A UTC comparison here would silently do
     * nothing for two hours of every Greek day.
     */
    public function testTheSweepPromotesAContractSignedLongEnoughAgo(): void
    {
        $this->markSignedAt($this->contractId, '-1 hour');

        ECRM_REST::run_auto_process();

        self::assertSame('processing', $this->statusOnDisk());
    }

    /** A fresh signature is left alone until its delay has passed. */
    public function testTheSweepLeavesARecentSignatureAlone(): void
    {
        $this->markSignedAt($this->contractId, 'now');

        ECRM_REST::run_auto_process();

        self::assertSame('signed', $this->statusOnDisk());
    }

    /**
     * The sweep never overrides a human.
     *
     * It selects on `status = 'signed'`, so an agent who already moved the
     * contract forward keeps their decision. Without this the sweep would drag
     * contracts backwards five minutes after anyone touched them.
     */
    public function testTheSweepDoesNotTouchAContractSomebodyAlreadyMovedOn(): void
    {
        $this->markSignedAt($this->contractId, '-1 hour');

        ECRM_REST::transition($this->contractId, 'routed', ['from' => 'signed']);

        ECRM_REST::run_auto_process();

        self::assertSame('routed', $this->statusOnDisk());
    }

    /** Straight from the table, so the assertion does not lean on the code under test. */
    private function statusOnDisk(): string
    {
        return (string) $this->storedRow('contracts', $this->contractId)['status'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventsFor(int $contractId): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE contract_id = %d ORDER BY id',
                Tables::name('events'),
                $contractId
            ),
            ARRAY_A
        );

        return $rows;
    }

    /** Put a contract in `signed` with a signature timestamp of our choosing. */
    private function markSignedAt(int $contractId, string $when): void
    {
        global $wpdb;

        $wpdb->update(
            Tables::name('contracts'),
            [
                'status'    => 'signed',
                'signed_at' => gmdate('Y-m-d H:i:s', strtotime($when, (int) current_time('timestamp'))),
            ],
            ['id' => $contractId]
        );
    }
}
