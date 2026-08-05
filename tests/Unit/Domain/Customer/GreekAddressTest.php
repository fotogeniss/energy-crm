<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Customer;

use EnergyCRM\Domain\Customer\GreekAddress;
use PHPUnit\Framework\TestCase;

final class GreekAddressTest extends TestCase
{
    public function testItSplitsTheShapeTheVatRegisterReturns(): void
    {
        self::assertSame(
            ['street' => 'ΣΟΛΩΜΟΥ', 'street_no' => '15', 'postal_code' => '42100', 'city' => 'ΤΡΙΚΑΛΑ'],
            GreekAddress::parse("ΣΟΛΩΜΟΥ 15\n42100 ΤΡΙΚΑΛΑ")
        );
    }

    public function testAStreetNumberMayCarryALetter(): void
    {
        $parsed = GreekAddress::parse('ΑΓΙΟΥ ΛΟΥΚΑ 15Α 19002 ΠΑΙΑΝΙΑ');

        self::assertSame('15Α', $parsed['street_no']);
        self::assertSame('ΑΓΙΟΥ ΛΟΥΚΑ', $parsed['street']);
    }

    public function testAMultiWordTownStaysWhole(): void
    {
        $parsed = GreekAddress::parse('ΑΡΤΕΜΙΔΟΣ 8 15125 ΠΑΡΑΔΕΙΣΟΣ ΑΜΑΡΟΥΣΙΟΥ');

        self::assertSame('ΠΑΡΑΔΕΙΣΟΣ ΑΜΑΡΟΥΣΙΟΥ', $parsed['city']);
    }

    /**
     * The register's formatting is not a contract, so anything unrecognised
     * belongs in the street rather than being thrown away.
     */
    public function testWhatCannotBePlacedGoesToTheStreet(): void
    {
        $parsed = GreekAddress::parse('ΚΑΠΟΥ ΣΤΗΝ ΑΘΗΝΑ');

        self::assertSame('ΚΑΠΟΥ ΣΤΗΝ ΑΘΗΝΑ', $parsed['street']);
        self::assertSame('', $parsed['street_no']);
        self::assertSame('', $parsed['postal_code']);
    }

    public function testAnEmptyAddressYieldsEmptyParts(): void
    {
        self::assertSame(
            ['street' => '', 'street_no' => '', 'postal_code' => '', 'city' => ''],
            GreekAddress::parse("   \n  ")
        );
    }

    public function testLineBreaksAndDoubleSpacesAreNoise(): void
    {
        self::assertSame(
            GreekAddress::parse('ΣΟΛΩΜΟΥ 15 42100 ΤΡΙΚΑΛΑ'),
            GreekAddress::parse("ΣΟΛΩΜΟΥ  15\r\n 42100   ΤΡΙΚΑΛΑ")
        );
    }
}
