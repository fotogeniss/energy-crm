<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\ContractTerm;
use PHPUnit\Framework\TestCase;

final class ContractTermTest extends TestCase
{
    public function testTheTermIsCountedInCalendarMonths(): void
    {
        self::assertSame('2027-03-03', ContractTerm::endDate('2026-03-03', 12));
        self::assertSame('2026-09-03', ContractTerm::endDate('2026-03-03', 6));
    }

    public function testAnExplicitEndDateAlwaysWins(): void
    {
        self::assertSame(
            '2026-12-31',
            ContractTerm::endDate('2026-03-03', 12, '2026-12-31')
        );
    }

    public function testAnOpenEndedContractHasNoEndDate(): void
    {
        self::assertNull(ContractTerm::endDate('2026-03-03', 0));
    }

    public function testWithoutAStartDateThereIsNothingToCountFrom(): void
    {
        self::assertNull(ContractTerm::endDate('', 12));
    }

    public function testAnUnreadableStartDateYieldsNothingRatherThanToday(): void
    {
        self::assertNull(ContractTerm::endDate('όχι ημερομηνία', 12));
    }

    /**
     * (404) Το τέλος του μήνα μένει στον μήνα του. Ως τότε το τεστ κατέγραφε
     * τη συμπεριφορά της PHP (31/01 + 1 μήνας = 03/03) χωρίς να την έχει
     * διαλέξει κανείς· σε σύμβαση 6 μηνών από 31/08 η λήξη έβγαινε 03/03.
     */
    public function testMonthEndStaysInItsMonth(): void
    {
        self::assertSame('2026-02-28', ContractTerm::endDate('2026-01-31', 1));
        self::assertSame('2027-02-28', ContractTerm::endDate('2026-08-31', 6));
        self::assertSame('2029-02-28', ContractTerm::endDate('2028-02-29', 12));
        self::assertSame('2028-02-29', ContractTerm::endDate('2027-08-31', 6), 'Δίσεκτο: η 29η υπάρχει.');
        self::assertSame('2027-03-31', ContractTerm::endDate('2026-03-31', 12));
        self::assertSame('2026-12-15', ContractTerm::endDate('2026-11-15', 1));
    }

    public function testWhitespaceIsNotAValue(): void
    {
        self::assertNull(ContractTerm::endDate('   ', 12));
        self::assertSame('2027-03-03', ContractTerm::endDate('2026-03-03', 12, '  '));
    }

    public function testActivationWithoutATermStartsTodayForTwelveMonths(): void
    {
        self::assertSame(
            ['start_date' => '2026-10-30', 'term_months' => 12, 'end_date' => '2027-10-30'],
            ContractTerm::onActivation(null, null, null, '2026-10-30')
        );
    }

    public function testActivationKeepsWhatTheAgentTyped(): void
    {
        self::assertSame(
            ['start_date' => '2026-11-01', 'term_months' => 24, 'end_date' => '2028-11-01'],
            ContractTerm::onActivation('2026-11-01 00:00:00', 24, '', '2026-10-30')
        );
        self::assertNull(ContractTerm::onActivation('2026-11-01', 12, '2027-06-30', '2026-10-30'));
    }
}
