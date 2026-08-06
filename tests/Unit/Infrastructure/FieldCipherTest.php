<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use EnergyCRM\Infrastructure\FieldCipher;
use PHPUnit\Framework\TestCase;

final class FieldCipherTest extends TestCase
{
    private function cipher(string $salt = 'a-wp-config-salt'): FieldCipher
    {
        return new FieldCipher($salt);
    }

    public function testAValueSurvivesTheRoundTrip(): void
    {
        $cipher = $this->cipher();

        self::assertSame('123456789', $cipher->decrypt($cipher->encrypt('123456789')));
        self::assertSame('Οδός Αγίου Δημητρίου 14', $cipher->decrypt($cipher->encrypt('Οδός Αγίου Δημητρίου 14')));
    }

    public function testTheStoredValueDoesNotContainThePlaintext(): void
    {
        $stored = $this->cipher()->encrypt('123456789');

        self::assertStringNotContainsString('123456789', $stored);
        self::assertTrue(FieldCipher::isEncrypted($stored));
    }

    /** Randomised encryption: the same input must not produce the same bytes. */
    public function testTheSameValueEncryptsDifferentlyEachTime(): void
    {
        $cipher = $this->cipher();

        self::assertNotSame($cipher->encrypt('123456789'), $cipher->encrypt('123456789'));
    }

    /**
     * The tolerance that lets encryption arrive without a flag day — a
     * half-migrated table must not show anybody base64.
     */
    public function testPlaintextWrittenBeforeEncryptionStaysReadable(): void
    {
        self::assertSame('123456789', $this->cipher()->decrypt('123456789'));
        self::assertSame('', $this->cipher()->decrypt(''));
    }

    public function testEncryptingTwiceIsRefused(): void
    {
        $cipher = $this->cipher();
        $once   = $cipher->encrypt('123456789');

        self::assertSame($once, $cipher->encrypt($once));
    }

    public function testAnEmptyValueIsLeftAlone(): void
    {
        self::assertSame('', $this->cipher()->encrypt(''));
    }

    /** Wrong salts mean unrecoverable, and an empty field beats corrupt bytes. */
    public function testAValueFromDifferentSaltsDecryptsToNothing(): void
    {
        $stored = $this->cipher('the-original-salt')->encrypt('123456789');

        self::assertSame('', $this->cipher('someone-regenerated-the-salts')->decrypt($stored));
    }

    /** Stable, or an exact lookup would never find the row it just wrote. */
    public function testTheBlindIndexIsTheSameForTheSameValue(): void
    {
        $cipher = $this->cipher();

        self::assertSame($cipher->blindIndex('123456789'), $cipher->blindIndex('123456789'));
        self::assertSame($cipher->blindIndex('123456789'), $cipher->blindIndex('  123456789 '));
        self::assertNotSame($cipher->blindIndex('123456789'), $cipher->blindIndex('987654321'));
        self::assertSame('', $cipher->blindIndex('   '));
    }

    /**
     * Keyed, not a bare hash. Nine digits is a space a laptop enumerates in
     * seconds, so an unkeyed digest of an ΑΦΜ is a lookup table.
     */
    public function testTheBlindIndexIsWorthlessWithoutTheKey(): void
    {
        self::assertNotSame(
            $this->cipher('one-site')->blindIndex('123456789'),
            $this->cipher('another-site')->blindIndex('123456789')
        );

        self::assertNotSame(hash('sha256', '123456789'), $this->cipher()->blindIndex('123456789'));
    }

    /** Derived apart from the encryption key, so one leak does not cost both. */
    public function testTheIndexIsNotDerivedFromTheEncryptionKey(): void
    {
        $cipher = $this->cipher();
        $index  = $cipher->blindIndex('123456789');

        self::assertStringNotContainsString($index, $cipher->encrypt('123456789'));
    }
}
