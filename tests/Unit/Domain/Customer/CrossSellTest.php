<?php

/**
 * (333) «Δώσε του και αέριο / ρεύμα».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Customer;

use EnergyCRM\Domain\Customer\CrossSell;
use PHPUnit\Framework\TestCase;

final class CrossSellTest extends TestCase
{
    public function testGasIsOfferedToPowerCustomersAndBack(): void
    {
        self::assertSame('power', CrossSell::has('gas'));
        self::assertSame('gas', CrossSell::has('power'));
        self::assertSame('', CrossSell::has('mobile'));
        self::assertSame('cross_sell_gas', CrossSell::snoozeField('gas'));
        self::assertSame('', CrossSell::snoozeField('mobile'));
    }

    public function testNotNowLastsNinetyDays(): void
    {
        self::assertSame('2026-08-01', CrossSell::snoozedSince('2026-10-30'));
    }

    public function testTheNoteIsTidied(): void
    {
        self::assertSame('Όχι τώρα', CrossSell::snoozeNote('  '));
        self::assertSame('Όχι τώρα — δεν έχει δίκτυο αερίου', CrossSell::snoozeNote(" δεν  έχει\nδίκτυο αερίου "));
    }
}
