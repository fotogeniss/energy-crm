<?php

/**
 * (388) Οι κανόνες του νέου κωδικού, ίδιοι με αυτούς που βλέπει ο χρήστης.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Security;

use EnergyCRM\Domain\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    private const ME = ['k.nikas@gdg-ts.gr', 'knikas', 'Κωνσταντίνος Νίκας'];

    public function testALongUncommonPasswordPasses(): void
    {
        self::assertNull(PasswordPolicy::problem('πράσινο-λεμόνι-42', self::ME));
        self::assertNull(PasswordPolicy::problem('Thalassa.Vouno.9', self::ME));
    }

    public function testItNeedsTenCharacters(): void
    {
        self::assertNotNull(PasswordPolicy::problem('Abc!12345', self::ME));
        self::assertNull(PasswordPolicy::problem('Abc!123456x', self::ME));
    }

    public function testGreekCharactersCountAsOneEach(): void
    {
        // 10 ελληνικοί χαρακτήρες = 20 bytes, αλλά 10 χαρακτήρες.
        self::assertNull(PasswordPolicy::problem('θαλασσάκια', []));
        self::assertNotNull(PasswordPolicy::problem('θαλασσάκι', []));
    }

    public function testItRefusesTheEmailOrTheName(): void
    {
        self::assertNotNull(PasswordPolicy::problem('k.nikas2026!', self::ME));
        self::assertNotNull(PasswordPolicy::problem('KNIKAS-1234', self::ME));
        self::assertNotNull(PasswordPolicy::problem('Νίκας-και-1234', self::ME));
    }

    public function testTheEmailDomainIsNotAWordToAvoid(): void
    {
        // «gdg», «ts», «gr» και «com» δεν αποκλείουν τίποτα.
        self::assertNull(PasswordPolicy::problem('company-gr-gdg-77', ['someone@gdg-ts.gr']));
    }

    public function testCommonPasswordsAreRefused(): void
    {
        self::assertNotNull(PasswordPolicy::problem('1234567890', []));
        self::assertNotNull(PasswordPolicy::problem('Password123', []));
        self::assertNotNull(PasswordPolicy::problem('aaaaaaaaaaaa', []));
    }

    public function testThereIsAnUpperLimit(): void
    {
        self::assertNotNull(PasswordPolicy::problem(str_repeat('ab', 101), []));
    }
}
