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
    public function testWonIsThePayableStatusesAndLostIsTheTerminalOnes(): void
    {
        $result = Funnel::from([
            'routed'     => 3,
            'active'     => 2,
            'resolved'   => 1,
            'cancelled'  => 2,
            'terminated' => 2,
            'processing' => 10,
        ]);

        self::assertSame(6, $result['won']);
        self::assertSame(4, $result['lost']);
        self::assertSame(20, $result['total']);
    }

    /** In-flight work counts towards neither side, only the denominator. */
    public function testContractsStillInProgressAreNeitherWonNorLost(): void
    {
        $result = Funnel::from(['new' => 5, 'processing' => 5]);

        self::assertSame(0, $result['won']);
        self::assertSame(0, $result['lost']);
        self::assertSame(10, $result['total']);
        self::assertSame(0.0, $result['conv_rate']);
    }

    public function testRatesAreRoundedToOneDecimal(): void
    {
        $result = Funnel::from(['active' => 1, 'processing' => 2]);

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
