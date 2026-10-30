<?php

/**
 * (329) Ο πίνακας εγγυήσεων ΦΑ της Protergia (Version 16).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Guarantee;

use EnergyCRM\Domain\Forms\ProtergiaGasSheets;
use EnergyCRM\Domain\Guarantee\GasMeterGuarantee;
use PHPUnit\Framework\TestCase;

final class GasMeterGuaranteeTest extends TestCase
{
    public function testTheAmountComesFromMeterAndCategory(): void
    {
        self::assertSame(60.0, GasMeterGuarantee::suggest('G4', 'home', 'manual', 'new_connection')['amount']);
        self::assertSame(250.0, GasMeterGuarantee::suggest('G10', 'communal', '', '')['amount']);
        self::assertSame(1000.0, GasMeterGuarantee::suggest('G25', 'business', 'manual', '')['amount']);
        self::assertSame(4500.0, GasMeterGuarantee::suggest('G160', 'business', '', '')['amount']);
    }

    public function testStandingOrderMeansZero(): void
    {
        $out = GasMeterGuarantee::suggest('G25', 'business', 'standing_order', '');

        self::assertSame(0.0, $out['amount']);
        self::assertStringContainsString('πάγια εντολή', $out['reason']);
    }

    public function testCommunalFromG40HasNoGuaranteeButSaysSo(): void
    {
        $out = GasMeterGuarantee::suggest('G40', 'communal', 'manual', '');

        self::assertNull($out['amount']);
        self::assertStringContainsString('δεν προβλέπει', $out['reason']);
    }

    public function testASameUserChangeIsToldThePreviousGuaranteeMayApply(): void
    {
        $out = GasMeterGuarantee::suggest('G4', 'home', 'manual', 'change_provider');

        self::assertSame(60.0, $out['amount']);
        self::assertStringContainsString('ίδιος χρήστης', $out['reason']);
    }

    public function testUnknownMeterOrCategoryLeavesItToTheRules(): void
    {
        self::assertNull(GasMeterGuarantee::suggest('', 'home', '', ''));
        self::assertNull(GasMeterGuarantee::suggest('G7', 'home', '', ''));
        self::assertNull(GasMeterGuarantee::suggest('G4', 'other', '', ''));
    }

    public function testEveryMeterTheFormOffersIsInTheTable(): void
    {
        foreach (ProtergiaGasSheets::METER_TYPES as $meter) {
            self::assertSame($meter, GasMeterGuarantee::meter($meter));
        }

        self::assertSame('G2,5', GasMeterGuarantee::meter('g 2.5'));
    }
}
