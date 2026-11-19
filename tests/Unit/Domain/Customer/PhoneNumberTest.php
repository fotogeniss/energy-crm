<?php

/**
 * (422) Ο ίδιος αριθμός, όπως κι αν γράφτηκε, δίνει το ίδιο κλειδί.
 *
 * @kanonas K11
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Customer;

use EnergyCRM\Domain\Customer\PhoneNumber;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase
{
    public function testTheSameNumberWrittenInManyWays(): void
    {
        $written = [
            '6971234567',
            '697 123 4567',
            '697-123-4567',
            '+30 697 1234567',
            '0030 6971234567',
            '(+30) 697.123.4567',
        ];

        foreach ($written as $typed) {
            self::assertSame('6971234567', PhoneNumber::key($typed), $typed);
        }
    }

    public function testALandlineToo(): void
    {
        self::assertSame('2101234567', PhoneNumber::key('210 123 4567'));
        self::assertSame('2101234567', PhoneNumber::key('+30 210 1234567'));
    }

    public function testAForeignNumberKeepsItsCode(): void
    {
        self::assertSame('4915112345678', PhoneNumber::key('+49 151 12345678'));
        self::assertSame('4915112345678', PhoneNumber::key('0049 151 12345678'));
    }

    public function testNothingToKeep(): void
    {
        self::assertSame('', PhoneNumber::key(''));
        self::assertSame('', PhoneNumber::key('δεν έχει'));
    }

    public function testOnlyAPhoneLikeTermBecomesDigits(): void
    {
        self::assertSame('6971234567', PhoneNumber::likeTerm('+30 697 123 4567'));
        self::assertSame('697123', PhoneNumber::likeTerm('697 123'));
        self::assertSame('ΕΝ-12', PhoneNumber::likeTerm('ΕΝ-12'));
        self::assertSame('Παπαδόπουλος', PhoneNumber::likeTerm('Παπαδόπουλος'));
        self::assertSame('-', PhoneNumber::likeTerm('-'));
    }

    public function testTheColumnIsStrippedOfTheSameSeparators(): void
    {
        $sql = PhoneNumber::sql('cu.mobile');

        self::assertStringContainsString("COALESCE(cu.mobile, '')", $sql);
        foreach ([' ', '-', '.', '(', ')', '/'] as $char) {
            self::assertStringContainsString("'{$char}', ''", $sql);
        }
    }
}
