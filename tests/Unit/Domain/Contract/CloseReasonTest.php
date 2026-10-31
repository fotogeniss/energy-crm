<?php

/**
 * (342) Λόγος απόρριψης/ακύρωσης -- STATUS-MODEL Κ5 και §7.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\CloseReason;
use PHPUnit\Framework\TestCase;

final class CloseReasonTest extends TestCase
{
    public function testOnlyRejectionsAndCancellationAskForAReason(): void
    {
        self::assertTrue(CloseReason::required('thalis_rejected'));
        self::assertTrue(CloseReason::required('mnp_reject'));
        self::assertTrue(CloseReason::required('cancelled'));
        self::assertFalse(CloseReason::required('active'));
        self::assertFalse(CloseReason::required('registration'));
    }

    public function testThrowingAwayADraftNeedsNoReason(): void
    {
        self::assertFalse(CloseReason::required('cancelled', 'draft'));
        self::assertNull(CloseReason::problem('cancelled', '', '', 'draft'));
        self::assertTrue(CloseReason::required('cancelled', 'presale'));
    }

    public function testTheCancelListIsTheFiveOfStatusModelPlusTwo(): void
    {
        self::assertSame(
            ['contract_lock', 'retention', 'acceptance', 'changed_mind', 'incomplete', 'provider_reject', 'other'],
            array_keys(CloseReason::for('cancelled'))
        );
        self::assertSame(array_keys(CloseReason::for('thalis_rejected')), array_keys(CloseReason::for('mnp_reject')));
    }

    public function testAReasonFromTheWrongListOrAnEmptyOtherIsRefused(): void
    {
        self::assertSame('Διάλεξε λόγο.', CloseReason::problem('cancelled', '', ''));
        self::assertSame(
            'Διάλεξε λόγο.',
            CloseReason::problem('cancelled', 'debt', ''),
            'το debt είναι λόγος απόρριψης'
        );
        self::assertSame('Γράψε τι έγινε στο «Άλλο».', CloseReason::problem('thalis_rejected', 'other', '  '));
        self::assertNull(CloseReason::problem('thalis_rejected', 'other', 'ο πάροχος δεν απάντησε'));
        self::assertNull(CloseReason::problem('thalis_rejected', 'debt', ''));
        self::assertNull(CloseReason::problem('active', '', ''));
    }

    public function testLabelsForReports(): void
    {
        self::assertSame('Οφειλή σε προηγούμενο πάροχο', CloseReason::label('debt'));
        self::assertSame('Το μετάνιωσε', CloseReason::label('changed_mind'));
        self::assertSame('Χωρίς λόγο', CloseReason::label(''));
        self::assertSame(['thalis_rejected', 'mnp_reject', 'cancelled'], array_keys(CloseReason::all()));
        self::assertSame(['debt', 'Οφειλή σε προηγούμενο πάροχο'], CloseReason::all()['thalis_rejected'][3]);
    }
}
