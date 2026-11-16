<?php

/**
 * (398) Τα δικαιώματα του wp-config.php που δέχεται η «Υγεία»: 0600 και 0640
 * (όπως λέει και το μήνυμα), όχι 0644 ή ό,τι αφήνει την ομάδα να γράφει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use EnergyCRM\Infrastructure\HealthChecks;
use PHPUnit\Framework\TestCase;

final class ConfigPermissionsTest extends TestCase
{
    public function testWhatTheMessageRecommendsPasses(): void
    {
        self::assertFalse(HealthChecks::configTooOpen(0o600));
        self::assertFalse(HealthChecks::configTooOpen(0o640));
        self::assertFalse(HealthChecks::configTooOpen(0o400));
        self::assertFalse(HealthChecks::configTooOpen(0o100640), 'Με τα bits τύπου αρχείου από το fileperms().');
    }

    public function testReadableByOthersOrWritableByTheGroupFails(): void
    {
        self::assertTrue(HealthChecks::configTooOpen(0o644));
        self::assertTrue(HealthChecks::configTooOpen(0o604));
        self::assertTrue(HealthChecks::configTooOpen(0o660));
        self::assertTrue(HealthChecks::configTooOpen(0o666));
    }
}
