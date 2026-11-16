<?php

/**
 * (406) TOTP όπως το Google/Microsoft Authenticator: τα διανύσματα του RFC 6238.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Security;

use EnergyCRM\Domain\Security\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** Το μυστικό του RFC 6238 (SHA1): «12345678901234567890». */
    private static function rfcSecret(): string
    {
        return Totp::base32Encode('12345678901234567890');
    }

    /** @return iterable<string, array{int, string}> */
    public static function rfcVectors(): iterable
    {
        // Τα 8ψήφια του RFC, κομμένα στα 6 τελευταία (ίδιο mod 10^6).
        yield '59'         => [59, '287082'];
        yield '1111111109' => [1111111109, '081804'];
        yield '1111111111' => [1111111111, '050471'];
        yield '1234567890' => [1234567890, '005924'];
        yield '2000000000' => [2000000000, '279037'];
    }

    #[DataProvider('rfcVectors')]
    public function testTheRfcVectors(int $time, string $expected): void
    {
        self::assertSame($expected, Totp::code(self::rfcSecret(), Totp::stepAt($time)));
    }

    public function testOneStepOfClockDriftIsAccepted(): void
    {
        $secret = self::rfcSecret();
        $code   = Totp::code($secret, Totp::stepAt(1234567890));

        self::assertSame(Totp::stepAt(1234567890), Totp::verify($secret, $code, 1234567890 + 30));
        self::assertSame(Totp::stepAt(1234567890), Totp::verify($secret, $code, 1234567890 - 30));
        self::assertNull(Totp::verify($secret, $code, 1234567890 + 90));
    }

    public function testTheSameNumberIsNotAcceptedTwice(): void
    {
        $secret = self::rfcSecret();
        $step   = Totp::verify($secret, '005924', 1234567890);

        self::assertNotNull($step);
        self::assertNull(Totp::verify($secret, '005924', 1234567890, $step));
    }

    public function testSpacesAreIgnoredAndJunkIsRefused(): void
    {
        $secret = self::rfcSecret();

        self::assertNotNull(Totp::verify($secret, '005 924', 1234567890));
        self::assertNull(Totp::verify($secret, '12345', 1234567890));
        self::assertNull(Totp::verify($secret, 'abcdef', 1234567890));
        self::assertNull(Totp::verify('όχι base32', '005924', 1234567890));
    }

    public function testSecretsAreRandomBase32OfTwentyBytes(): void
    {
        $a = Totp::newSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a);
        self::assertNotSame($a, Totp::newSecret());
        self::assertSame(20, strlen(Totp::base32Decode($a)));
        self::assertSame(Totp::base32Decode($a), Totp::base32Decode(strtolower(Totp::grouped($a))));
    }

    public function testTheUriTheAppsRead(): void
    {
        self::assertSame(
            'otpauth://totp/Energy%20CRM:a%40b.gr?secret=JBSWY3DP&issuer=Energy%20CRM'
            . '&algorithm=SHA1&digits=6&period=30',
            Totp::uri('JBSWY3DP', 'a@b.gr', 'Energy CRM')
        );
    }
}
