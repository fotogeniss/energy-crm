<?php

/**
 * (343) Οι μικροί υπολογισμοί της καρτέλας «Κατάστημα».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Analytics;

use EnergyCRM\Domain\Analytics\StoreFigures;
use PHPUnit\Framework\TestCase;

final class StoreFiguresTest extends TestCase
{
    public function testChangeIsAPercentageRounded(): void
    {
        self::assertSame(12, StoreFigures::change(83, 74));
        self::assertSame(-50, StoreFigures::change(5, 10));
        self::assertSame(0, StoreFigures::change(7, 7));
    }

    /** Από 0 σε κάτι δεν είναι ποσοστό· η οθόνη δεν γράφει τίποτα. */
    public function testNoBaseMeansNoComparison(): void
    {
        self::assertNull(StoreFigures::change(5, 0));
        self::assertNull(StoreFigures::change(0, 0));
    }

    public function testSplitCountsMostFirstThenByName(): void
    {
        self::assertSame(
            [
                ['name' => 'Μαρία Κ.', 'count' => 2],
                ['name' => 'Γιάννης Π.', 'count' => 1],
                ['name' => 'Νίκος Τ.', 'count' => 1],
            ],
            StoreFigures::split(['Νίκος Τ.', 'Μαρία Κ.', 'Γιάννης Π.', 'Μαρία Κ.'])
        );
    }

    public function testSplitNamesTheUnknown(): void
    {
        self::assertSame([['name' => '—', 'count' => 1]], StoreFigures::split(['']));
        self::assertSame([], StoreFigures::split([]));
    }
}
