<?php

/**
 * (349) «Αριθμός Αίτησης»: τι φτάνει στη στήλη και στο έντυπο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use EnergyCRM\Domain\Contract\ApplicationNumber;
use PHPUnit\Framework\TestCase;

final class ApplicationNumberTest extends TestCase
{
    public function testTheProvidersSerialStaysAsWritten(): void
    {
        self::assertSame('1853-1-0046485', ApplicationNumber::normalize('1853-1-0046485'));
        self::assertSame('E-12/2026', ApplicationNumber::normalize('E-12/2026'));
    }

    public function testSpacesAreTrimmedAndCollapsed(): void
    {
        self::assertSame('1853 1 0046485', ApplicationNumber::normalize("  1853   1\t0046485 "));
    }

    /** Ό,τι δεν είναι αριθμοσειρά δεν φτάνει στο έντυπο. */
    public function testMarkupAndSymbolsAreDropped(): void
    {
        self::assertSame('1853-1', ApplicationNumber::normalize('<1853-1>'));
        self::assertSame('1853', ApplicationNumber::normalize('1853;"\''));
    }

    public function testEmptyIsNull(): void
    {
        self::assertNull(ApplicationNumber::normalize(''));
        self::assertNull(ApplicationNumber::normalize('   '));
        self::assertNull(ApplicationNumber::normalize('<>'));
    }

    public function testCutToTheColumn(): void
    {
        self::assertSame(ApplicationNumber::MAX, mb_strlen((string) ApplicationNumber::normalize(str_repeat('9', 60))));
    }
}
