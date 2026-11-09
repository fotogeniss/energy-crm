<?php

/**
 * (352) Η επικεφαλίδα Permissions-Policy αφήνει την κάμερα στο ίδιο το site.
 *
 * Με `camera=()` το iPhone άνοιγε την κάμερα μαύρη όταν ο πωλητής
 * φωτογράφιζε λογαριασμό (09/11/2026, δύο συσκευές).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CameraIsAllowedTest extends TestCase
{
    public function testTheSiteMayUseTheCamera(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/class-ecrm-security.php');

        self::assertStringNotContainsString('camera=()', $source);
        self::assertStringContainsString('camera=(self)', $source);
    }
}
