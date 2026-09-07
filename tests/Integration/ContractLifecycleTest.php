<?php

/**
 * What a status change actually does.
 *
 * Written as a characterisation suite the day before the code moved: every
 * assertion described `ECRM_REST::transition()` as it stood, deliberately,
 * including the corners that look odd. The move out to ContractLifecycle then
 * changed exactly one thing in this file — the name of what gets called — and
 * nothing else. That is what made it safe to make.
 *
 * It earns its place regardless. This is one of the riskiest paths in the
 * plugin: it writes the status, appends to the event log, and fires the
 * in-app notification and the SMS — and every signature in the system passes
 * through it. None of that was covered by anything.
 *
 * 07/09/2026: it used to also schedule a cron job (AutoProcess) five minutes
 * after a signature. That mechanism is gone — the owner chose immediate
 * progression instead, so `signed_at` is written and the next status is
 * computed and applied in the same request (see `MobileLine` and
 * `class-ecrm-tracking.php::rest_sign()`). Nothing here schedules anything
 * any more, which is why the sweep tests that used to live here are gone too.
 *
 * SMS is off unless `sms_enabled` is '1', which no test sets.
 *
 * Authorisation is not tested here because it does not live here. `moveTo()`
 * takes a raw id and trusts its caller; the controllers resolve the contract
 * through a scoped repository first. That is checked in ContractRestAccessTest.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\UserScope;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Services;

final class ContractLifecycleTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private ContractLifecycle $lifecycle;

    private int $partner;

    private int $contractId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->lifecycle = Services::lifecycle();

        $this->partner = $this->makePartner();

        $this->contractId = $this->contracts->create(
            ['status' => 'presale', 'supply_number' => '12345678901', 'energy_type' => 'power'],
            UserScope::forSelf($this->partner)
        );

        self::assertGreaterThan(0, $this->contractId);
    }

    public function testAPermittedMoveIsApplied(): void
    {
        self::assertTrue($this->lifecycle->moveTo($this->contractId, 'registration'));
        self::assertSame('registration', $this->statusOnDisk());
    }

    /** An unknown slug is refused before anything is written. */
    public function testAStatusThatDoesNotExistChangesNothing(): void
    {
        self::assertFalse($this->lifecycle->moveTo($this->contractId, 'not_a_status'));

        self::assertSame('presale', $this->statusOnDisk());
        self::assertSame([], $this->eventsFor($this->contractId));
    }

    /**
     * The pipeline graph is enforced here, not only in the controller.
     *
     * A cancellation is terminal. If this ever passes, a cancelled contract
     * can be brought back to life by any caller that skips the controller.
     */
    public function testAMoveThePipelineForbidsIsRefused(): void
    {
        $this->lifecycle->moveTo($this->contractId, 'cancelled_by_us');

        self::assertFalse($this->lifecycle->moveTo($this->contractId, 'registration'));
        self::assertSame('cancelled_by_us', $this->statusOnDisk());
    }

    /**
     * Asking for the status it already has succeeds and writes nothing.
     *
     * True rather than false on purpose: the caller asked for a state, and the
     * contract is in that state. But no event is logged, because nothing
     * happened — a log full of "presale → presale" would bury the real
     * history.
     */
    public function testMovingToTheStatusItAlreadyHasSucceedsSilently(): void
    {
        self::assertTrue($this->lifecycle->moveTo($this->contractId, 'presale'));
        self::assertSame([], $this->eventsFor($this->contractId));
    }

    /** `force` is how a caller says "log it anyway", e.g. to re-run side effects. */
    public function testForceWritesTheEventEvenWithoutAChange(): void
    {
        self::assertTrue($this->lifecycle->moveTo($this->contractId, 'presale', ['force' => true]));
        self::assertCount(1, $this->eventsFor($this->contractId));
    }

    public function testTheEventRecordsWhoMovedItAndFromWhere(): void
    {
        $this->lifecycle->moveTo($this->contractId, 'registration', [
            'user_id' => $this->partner,
            'message' => 'Χειροκίνητη μετάβαση',
        ]);

        $events = $this->eventsFor($this->contractId);

        self::assertCount(1, $events);
        self::assertSame('status_change', $events[0]['type']);
        self::assertSame($this->partner, (int) $events[0]['user_id']);
        self::assertSame('presale', $events[0]['from_status']);
        self::assertSame('registration', $events[0]['to_status']);
        self::assertSame('Χειροκίνητη μετάβαση', $events[0]['message']);
    }

    /**
     * An empty origin is stored as NULL, not as ''.
     *
     * Callers that genuinely do not know the previous status pass `from => null`
     * — the signing route does exactly this. NULL says "unknown"; an empty string
     * would read as a status that does not exist.
     */
    public function testAnUnknownOriginIsStoredAsNull(): void
    {
        $this->lifecycle->moveTo($this->contractId, 'registration', ['from' => null]);

        self::assertNull($this->eventsFor($this->contractId)[0]['from_status']);
    }

    /**
     * `extra` is how the signature audit columns are written in the same
     * UPDATE — status-agnostic, so it is tested from a status that can
     * legally reach a next step regardless of which one.
     *
     * 07/09: ο στόχος είναι σκόπιμα `registration`, ΟΧΙ `finalisation` --
     * το `finalisation` είναι πλέον φυλασσόμενο (`PaperworkGate::
     * gate_statuses()`) και θα αρνιόταν τη μετάβαση χωρίς πλήρη χαρτιά και
     * υπογραφή, κάτι άσχετο με το τι δοκιμάζει αυτό το test. Το `extra` δεν
     * ξέρει ούτε νοιάζεται ποια κατάσταση είναι ο στόχος -- οποιαδήποτε
     * νόμιμη, μη φυλασσόμενη μετάβαση αποδεικνύει το ίδιο πράγμα.
     */
    public function testExtraColumnsAreWrittenAlongsideTheStatus(): void
    {
        $this->setStatusDirectly($this->contractId, 'awaiting_signature');

        $this->lifecycle->moveTo($this->contractId, 'registration', [
            'extra' => ['signed_ip' => '198.51.100.7'],
        ]);

        $row = $this->storedRow('contracts', $this->contractId);

        self::assertSame('registration', $row['status']);
        self::assertSame('198.51.100.7', $row['signed_ip']);
    }

    /** Straight from the table, so the assertion does not lean on the code under test. */
    private function statusOnDisk(): string
    {
        return (string) $this->storedRow('contracts', $this->contractId)['status'];
    }

    /** Bypasses the pipeline to set up a fixture in a given status directly. */
    private function setStatusDirectly(int $contractId, string $status): void
    {
        global $wpdb;

        $wpdb->update(Tables::name('contracts'), ['status' => $status], ['id' => $contractId]);
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
}
