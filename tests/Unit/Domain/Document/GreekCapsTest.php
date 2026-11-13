<?php

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Document;

use EnergyCRM\Domain\Document\GreekCaps;
use PHPUnit\Framework\TestCase;

final class GreekCapsTest extends TestCase
{
    /** Κανόνας ιδιοκτήτη: κεφαλαία ποτέ με τόνους. */
    public function testCapitalsHaveNoAccents(): void
    {
        self::assertSame('ΤΙΜΗ ΠΡΟΤΑΣΗΣ', GreekCaps::upper('Τιμή πρότασης'));
        self::assertSame('ΕΚΤΙΜΩΜΕΝΗ ΕΤΗΣΙΑ ΕΞΟΙΚΟΝΟΜΗΣΗ', GreekCaps::upper('Εκτιμώμενη ετήσια εξοικονόμηση'));
        self::assertSame('ΑΥΤΟΝΟΜΟ ΥΔΡΟΒΙΟ ΩΡΑ ΕΝΕΡΓΗ', GreekCaps::upper('αυτόνομο ύδροβιο ώρα Ενεργή'));
    }

    /** Τα διαλυτικά μένουν, ο τόνος από το ΐ/ΰ φεύγει. */
    public function testDiaeresisStaysAccentGoes(): void
    {
        self::assertSame('ΠΡΩΤΕΪΝΗ ΡΟΪΚΟ Ϋ', GreekCaps::upper('πρωτεΐνη ροϊκό ΰ'));
    }

    public function testLatinIsUntouched(): void
    {
        self::assertSame('VALUE SPECIAL 2.0', GreekCaps::upper('Value Special 2.0'));
    }
}
