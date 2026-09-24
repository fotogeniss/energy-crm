<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\SignatureBox;
use PHPUnit\Framework\TestCase;

final class SignatureBoxTest extends TestCase
{
    private const SLOT = ['page' => 3, 'x' => 150.0, 'y' => 147.8, 'w' => 36, 'h' => 0, 'fit_h' => 10.9];

    /** Θέση χωρίς `fit_h`: ακριβώς ό,τι τύπωνε πάντα -- οι άλλοι πάροχοι δεν αλλάζουν. */
    public function testASlotWithoutABoxIsLeftAlone(): void
    {
        $legacy = ['page' => 2, 'x' => 18.1, 'y' => 298.0, 'w' => 46, 'h' => 0];

        self::assertSame(
            ['x' => 18.1, 'y' => 298.0, 'w' => 46.0, 'h' => 0.0],
            SignatureBox::place($legacy, 540, 164)
        );
    }

    /** Υπογραφή από υπολογιστή (pad ~3,3:1): γεμίζει το κουτί και κάθεται στη γραμμή. */
    public function testADesktopSignatureSitsOnTheLine(): void
    {
        $p = SignatureBox::place(self::SLOT, 540, 164);

        $this->assertInsideTheBoxOnTheLine($p);
        self::assertGreaterThan(35.0, $p['w']);
    }

    /**
     * Υπογραφή από κινητό (pad ~1,8:1). Ως τώρα έβγαινε ~20mm ψηλή, κρεμασμένη
     * από πάνω, και κατέβαινε πάνω στο κείμενο από κάτω. Τώρα μικραίνει ώσπου
     * να χωρέσει, και κάθεται πάλι στη γραμμή.
     */
    public function testAPhoneSignatureShrinksIntoTheBoxInsteadOfSpillingBelow(): void
    {
        $p = SignatureBox::place(self::SLOT, 990, 540);

        $this->assertInsideTheBoxOnTheLine($p);
        self::assertEqualsWithDelta(10.9, $p['h'], 0.001);
        self::assertLessThan(36.0, $p['w']);
    }

    /** Πολύ πλατιά και χαμηλή: πλάτος όσο το κουτί, και πάλι ακουμπά κάτω -- όχι στο ταβάνι. */
    public function testAFlatSignatureStillRestsOnTheLine(): void
    {
        $p = SignatureBox::place(self::SLOT, 1000, 100);

        $this->assertInsideTheBoxOnTheLine($p);
        self::assertEqualsWithDelta(36.0, $p['w'], 0.001);
        self::assertGreaterThan(self::SLOT['y'] + 5, $p['y']);
    }

    /** Εικόνα που δεν διαβάστηκε: καμία διαίρεση με το μηδέν, η θέση όπως είναι. */
    public function testAnUnreadableImageFallsBackToTheSlot(): void
    {
        $p = SignatureBox::place(self::SLOT, 0, 0);

        self::assertSame(147.8, $p['y']);
        self::assertSame(36.0, $p['w']);
    }

    /** @param array{x: float, y: float, w: float, h: float} $p */
    private function assertInsideTheBoxOnTheLine(array $p): void
    {
        $bottom = self::SLOT['y'] + self::SLOT['fit_h'];

        self::assertEqualsWithDelta($bottom, $p['y'] + $p['h'], 0.001, 'Η υπογραφή δεν ακουμπά στη γραμμή.');
        self::assertGreaterThanOrEqual(self::SLOT['y'] - 0.001, $p['y'], 'Η υπογραφή βγαίνει πάνω από το κουτί.');
        self::assertLessThanOrEqual(self::SLOT['w'] + 0.001, $p['w'], 'Η υπογραφή βγαίνει δεξιά από το κουτί.');
        self::assertSame(self::SLOT['x'], $p['x']);
    }
}
