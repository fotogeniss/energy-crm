<?php

/**
 * `DocumentProtection` -- η οδήγηση cron γύρω από `UnprotectedDocuments`.
 *
 * AUDIT εύρημα §2.5 (EKKREMI-29-08.html): "το repository δοκιμασμένο, ο
 * οδηγός όχι" -- το `UnprotectedDocumentsTest` καλύπτει ήδη καλά το
 * `protectBatch()`/`count()`, αλλά κανένα test δεν άγγιζε ποτέ την ίδια την
 * κλάση `DocumentProtection`: το αν το `register()` πράγματι προγραμματίζει
 * το hook, το αν το `unschedule()` το καθαρίζει, ούτε το αν `pending()`/
 * `sweep()` περνάνε σωστά στο repository από κάτω.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Infrastructure\DocumentProtection;
use EnergyCRM\Services;

final class DocumentProtectionDriverTest extends IntegrationTestCase
{
    private DocumentProtection $protection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->protection = new DocumentProtection(Services::unprotectedDocuments());
    }

    protected function tearDown(): void
    {
        DocumentProtection::unschedule();

        parent::tearDown();
    }

    public function testRegisterSchedulesTheHourlyHookWhenNothingIsScheduledYet(): void
    {
        DocumentProtection::unschedule();
        self::assertFalse(wp_next_scheduled(DocumentProtection::HOOK));

        $this->protection->register();

        self::assertNotFalse(wp_next_scheduled(DocumentProtection::HOOK));
    }

    public function testRegisterDoesNotDoubleScheduleAnAlreadyPendingHook(): void
    {
        $this->protection->register();
        $first = wp_next_scheduled(DocumentProtection::HOOK);

        $this->protection->register();
        $second = wp_next_scheduled(DocumentProtection::HOOK);

        self::assertSame($first, $second, 'A second register() must not add a duplicate cron entry.');
    }

    public function testUnscheduleClearsThePendingHook(): void
    {
        $this->protection->register();
        self::assertNotFalse(wp_next_scheduled(DocumentProtection::HOOK));

        DocumentProtection::unschedule();

        self::assertFalse(wp_next_scheduled(DocumentProtection::HOOK));
    }

    /** pending() must reflect the real backlog, not a cached or fixed number. */
    public function testPendingDelegatesToTheRepositoryCount(): void
    {
        self::assertSame(Services::unprotectedDocuments()->count(), $this->protection->pending());
    }

    /** onScheduledSweep() is the cron entry point: it must run without throwing on an empty backlog. */
    public function testOnScheduledSweepRunsCleanlyWithNothingToProtect(): void
    {
        $this->protection->onScheduledSweep();

        self::assertTrue(true, 'onScheduledSweep() completed without an exception.');
    }
}
