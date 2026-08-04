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
     * A month later than the 31st is a real edge: PHP rolls it forward, which
     * is the behaviour we want recorded rather than discovered.
     */
    public function testMonthEndRollsForwardAsPhpDoes(): void
    {
        self::assertSame('2026-03-03', ContractTerm::endDate('2026-01-31', 1));
    }

    public function testWhitespaceIsNotAValue(): void
    {
        self::assertNull(ContractTerm::endDate('   ', 12));
        self::assertSame('2027-03-03', ContractTerm::endDate('2026-03-03', 12, '  '));
    }
}
