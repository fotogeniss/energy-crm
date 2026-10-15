<?php

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\TextFit;
use PHPUnit\Framework\TestCase;

final class TextFitTest extends TestCase
{
    /** Πλάτος ανάλογο του μεγέθους: 2 mm ανά pt (σαν ένα σταθερό κείμενο). */
    private static function w(): callable
    {
        return static fn (float $pt): float => $pt * 2.0;
    }

    public function testWithoutMaxwTheSizeIsUntouched(): void
    {
        self::assertSame(8.5, TextFit::size([], 8.5, self::w()));
        self::assertSame(8.5, TextFit::size(['maxw' => 0], 8.5, self::w()));
    }

    public function testTextThatFitsKeepsItsSize(): void
    {
        self::assertSame(8.5, TextFit::size(['maxw' => 17], 8.5, self::w()));
    }

    public function testTooWideTextShrinksToFit(): void
    {
        $size = TextFit::size(['maxw' => 14], 8.5, self::w());

        self::assertSame(7.0, $size);
        self::assertLessThanOrEqual(14.0, $size * 2.0);
    }

    public function testItNeverGoesBelowTheMinimum(): void
    {
        self::assertSame(TextFit::MIN, TextFit::size(['maxw' => 2], 8.5, self::w()));
    }

    public function testTheVoltonCompanyEmailIsBoundBeforeTheKadCaption(): void
    {
        $map = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/assets/forms/volton/volton_fa.json'),
            true
        );
        $company = array_values(array_filter(
            $map['fields']['email_pelati'],
            static fn (array $p): bool => ($p['when'] ?? '') === 'company'
        ))[0];

        // «ΚΑΔ:» αρχίζει στα 117,9 mm στο volton_fa-1.jpg.
        self::assertLessThanOrEqual(117.9, $company['x'] + $company['maxw']);
    }
}
