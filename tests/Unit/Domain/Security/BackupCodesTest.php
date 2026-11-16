<?php

/**
 * (406) Εφεδρικοί κωδικοί: οκτώ, μίας χρήσης, μόνο hash στη βάση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Security;

use EnergyCRM\Domain\Security\BackupCodes;
use PHPUnit\Framework\TestCase;

final class BackupCodesTest extends TestCase
{
    public function testEightDifferentCodesOfTheSameShape(): void
    {
        $codes = BackupCodes::generate();

        self::assertCount(8, $codes);
        self::assertCount(8, array_unique($codes));

        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $code);
        }
    }

    public function testTheHashIsNotTheCodeAndMatchesHowEverItIsTyped(): void
    {
        $hashes = [BackupCodes::hash('4821-7730'), BackupCodes::hash('1196-0452')];

        self::assertStringNotContainsString('48217730', $hashes[0]);
        self::assertSame(0, BackupCodes::match('4821-7730', $hashes));
        self::assertSame(0, BackupCodes::match('4821 7730', $hashes));
        self::assertSame(1, BackupCodes::match('11960452', $hashes));
        self::assertNull(BackupCodes::match('0000-0000', $hashes));
        self::assertNull(BackupCodes::match('4821', $hashes));
    }
}
