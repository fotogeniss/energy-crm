<?php

/**
 * (348) Παρατηρήσεις εκκρεμότητας: ποιος γράφει τι και πότε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\PendingRemarks;
use PHPUnit\Framework\TestCase;

final class PendingRemarksTest extends TestCase
{
    public function testOnlyBackOfficeWritesTheProviderRemark(): void
    {
        self::assertNotNull(PendingRemarks::problem('provider', 'pending_issue', 'κείμενο', false));
        self::assertNull(PendingRemarks::problem('provider', 'pending_issue', 'κείμενο', true));
    }

    public function testAnyoneWithTheContractWritesTheAgentRemark(): void
    {
        self::assertNull(PendingRemarks::problem('agent', 'pending_issue', 'Ζητήθηκε νέο ΑΔΤ', false));
    }

    public function testRemarksOnlyOpenInPendingIssue(): void
    {
        self::assertNotNull(PendingRemarks::problem('agent', 'active', 'κείμενο', true));
        self::assertNotNull(PendingRemarks::problem('provider', 'processing', 'κείμενο', true));
    }

    public function testEmptyTextAndUnknownKindAreRefused(): void
    {
        self::assertNotNull(PendingRemarks::problem('agent', 'pending_issue', "  \n ", false));
        self::assertNotNull(PendingRemarks::problem('boss', 'pending_issue', 'x', true));
    }

    public function testTextIsTrimmedAndCapped(): void
    {
        self::assertSame('abc', PendingRemarks::clean("  abc \n"));
        self::assertSame(PendingRemarks::MAX_LENGTH, mb_strlen(PendingRemarks::clean(str_repeat('α', 5000))));
    }

    public function testEventTypesRoundTripAndLabelsUseTheAgreedWording(): void
    {
        foreach (PendingRemarks::kinds() as $kind) {
            self::assertSame($kind, PendingRemarks::kindOf(PendingRemarks::eventType($kind)));
        }

        self::assertSame('Παρατηρήσεις Protergia', PendingRemarks::label('provider'));
        self::assertSame('Παρατηρήσεις Agent', PendingRemarks::label('agent'));
    }
}
