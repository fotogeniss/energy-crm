<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Analytics;

use EnergyCRM\Domain\Analytics\Funnel;
use EnergyCRM\Domain\Contract\ContractStatus;
use PHPUnit\Framework\TestCase;

final class FunnelTest extends TestCase
{
    /**
     * 07/09/2026: won = πληρωτέα (active) + ΔΙΑΚΟΠΗ (δούλεψε, δεν χάθηκε).
     * lost = οι δύο ακυρώσεις. Ό,τι άλλο είναι ακόμα σε εξέλιξη.
     */
    public function testWonIsActivePlusTerminatedAndLostIsTheTwoCancellations(): void
    {
        $result = Funnel::from([
            'presale'                => 3,
            'active'                 => 2,
            'terminated'             => 2,
            'cancelled_by_us'        => 1,
            'cancelled_by_customer'  => 1,
            'registration'           => 10,
        ]);

        self::assertSame(4, $result['won']);
        self::assertSame(2, $result['lost']);
        self::assertSame(19, $result['total']);
    }

    /** In-flight work counts towards neither side, only the denominator. */
    public function testContractsStillInProgressAreNeitherWonNorLost(): void
    {
        $result = Funnel::from(['presale' => 5, 'registration' => 5]);

        self::assertSame(0, $result['won']);
        self::assertSame(0, $result['lost']);
        self::assertSame(10, $result['total']);
        self::assertSame(0.0, $result['conv_rate']);
    }

    public function testRatesAreRoundedToOneDecimal(): void
    {
        $result = Funnel::from(['active' => 1, 'registration' => 2]);

        self::assertSame(33.3, $result['conv_rate']);
    }

    /** No contracts must read as zero per cent, not as a division by zero. */
    public function testAnEmptyPipelineHasZeroRates(): void
    {
        $result = Funnel::from([]);

        self::assertSame(0, $result['total']);
        self::assertSame(0.0, $result['conv_rate']);
        self::assertSame(0.0, $result['canc_rate']);
    }

    public function testTheFunnelListsEveryStatusIncludingEmptyOnes(): void
    {
        $result = Funnel::from(['active' => 1]);

        self::assertCount(count(ContractStatus::cases()), $result['funnel']);
        self::assertSame('draft', $result['funnel'][0]['status']);
        self::assertSame(0, $result['funnel'][0]['count']);
    }

    public function testStatusesAbsentFromTheCountsDoNotBreakTheTotal(): void
    {
        self::assertSame(1, Funnel::from(['active' => 1])['total']);
    }
}
