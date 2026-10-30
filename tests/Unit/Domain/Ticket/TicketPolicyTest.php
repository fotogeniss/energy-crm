<?php

/**
 * (339) Ποιος βλέπει, ποιος ξανανοίγει -- πίνακας «Ποιος τι κάνει» του
 * docs/UI-TICKETS.html.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Ticket;

use EnergyCRM\Domain\Ticket\TicketPolicy;
use EnergyCRM\Domain\Ticket\TicketStatus;
use PHPUnit\Framework\TestCase;

final class TicketPolicyTest extends TestCase
{
    public function testWhoseReportsEachRoleSees(): void
    {
        self::assertNull(TicketPolicy::reporters(1, true, true, []), 'διαχειριστής: όλες');
        self::assertSame([5], TicketPolicy::reporters(5, false, false, [5, 6, 7]), 'πωλητής/back office: δικές του');
        self::assertSame([5, 6, 7], TicketPolicy::reporters(5, false, true, [5, 6, 7]), 'υπεύθυνος: + ομάδα');

        self::assertTrue(TicketPolicy::canSee(null, 99));
        self::assertTrue(TicketPolicy::canSee([5, 6], 6));
        self::assertFalse(TicketPolicy::canSee([5, 6], 7));
    }

    public function testOnlyTheReporterReopensAResolvedReportWithinFourteenDays(): void
    {
        self::assertTrue(TicketPolicy::canReopen(TicketStatus::Resolved, true, 0));
        self::assertTrue(TicketPolicy::canReopen(TicketStatus::Resolved, true, 14));
        self::assertFalse(TicketPolicy::canReopen(TicketStatus::Resolved, true, 15));
        self::assertFalse(TicketPolicy::canReopen(TicketStatus::Resolved, false, 1), 'όχι άλλος');
        self::assertFalse(
            TicketPolicy::canReopen(TicketStatus::NotAnIssue, true, 1),
            '«Δεν είναι πρόβλημα» μένει κλειστό'
        );
        self::assertFalse(TicketPolicy::canReopen(TicketStatus::InProgress, true, null));
    }

    public function testTheTwoClosedStatuses(): void
    {
        self::assertSame(['new', 'in_progress'], TicketStatus::openValues());
        self::assertTrue(TicketStatus::Resolved->isClosed());
        self::assertTrue(TicketStatus::NotAnIssue->isClosed());
        self::assertFalse(TicketStatus::InProgress->isClosed());
        self::assertSame(
            [
                'new'         => 'Νέο',
                'in_progress' => 'Σε εξέλιξη',
                'resolved'    => 'Λύθηκε',
                'not_issue'   => 'Δεν είναι πρόβλημα',
            ],
            TicketStatus::labels()
        );
    }
}
