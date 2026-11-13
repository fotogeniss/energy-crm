<?php

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Quote;

use EnergyCRM\Domain\Quote\QuoteText;
use PHPUnit\Framework\TestCase;

final class QuoteTextTest extends TestCase
{
    public function testGreekNumbers(): void
    {
        self::assertSame('190,05 €', QuoteText::euro(190.05));
        self::assertSame('3.660', QuoteText::number(3660, 0));
        self::assertSame('0,13500', QuoteText::number(0.135, 5));
        self::assertSame('1.234,50 €', QuoteText::euro(1234.5));
    }

    public function testSignedDifference(): void
    {
        self::assertSame('−106,14 €', QuoteText::signedEuro(-106.14));
        self::assertSame('+12,00 €', QuoteText::signedEuro(12));
        self::assertSame('0,00 €', QuoteText::signedEuro(0.001));
    }

    public function testTariffWord(): void
    {
        self::assertSame('Σταθερό', QuoteText::tariff('fixed'));
        self::assertSame('Κυμαινόμενο', QuoteText::tariff('variable'));
        self::assertSame('', QuoteText::tariff(''));
    }
}
