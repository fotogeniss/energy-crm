<?php

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Profile;

use DateTimeImmutable;
use EnergyCRM\Domain\Profile\ProfilePrefs;
use PHPUnit\Framework\TestCase;

final class ProfilePrefsTest extends TestCase
{
    public function testUnknownKeysAndBadValuesFallBackToDefaults(): void
    {
        $p = ProfilePrefs::clean([
            'start_view' => 'wp-admin',
            'scope'      => 'everyone',
            'font'       => 'huge',
            'is_admin'   => true,
        ]);

        self::assertSame('dashboard', $p['start_view']);
        self::assertSame('', $p['scope']);
        self::assertSame('normal', $p['font']);
        self::assertArrayNotHasKey('is_admin', $p);
        self::assertTrue($p['notify_status_email']);
    }

    public function testValuesAreKept(): void
    {
        $p = ProfilePrefs::clean([
            'phone'               => '  +30 698   277 4563 ',
            'title'               => 'Σύμβουλος ενέργειας',
            'signature'           => "Κ. Νίκας\r\nGDG",
            'start_view'          => 'tasks',
            'scope'               => 'team',
            'font'                => 'large',
            'notify_digest_email' => false,
            'quiet'               => 'true',
            'fav_providers'       => ['3', 1, 3, -2, 'x'],
        ]);

        self::assertSame('+30 698 277 4563', $p['phone']);
        self::assertSame("Κ. Νίκας\nGDG", $p['signature']);
        self::assertSame('tasks', $p['start_view']);
        self::assertSame('team', $p['scope']);
        self::assertSame('large', $p['font']);
        self::assertFalse($p['notify_digest_email']);
        self::assertTrue($p['quiet']);
        self::assertSame([3, 1], $p['fav_providers']);
    }

    public function testLongTextIsCut(): void
    {
        $p = ProfilePrefs::clean(['title' => str_repeat('α', 200)]);

        self::assertSame(60, mb_strlen($p['title']));
    }

    public function testQuietHours(): void
    {
        // 13/11/2026 είναι Παρασκευή, 15/11/2026 Κυριακή.
        self::assertTrue(ProfilePrefs::isQuietTime(new DateTimeImmutable('2026-11-13 22:30')));
        self::assertTrue(ProfilePrefs::isQuietTime(new DateTimeImmutable('2026-11-13 07:59')));
        self::assertFalse(ProfilePrefs::isQuietTime(new DateTimeImmutable('2026-11-13 08:00')));
        self::assertFalse(ProfilePrefs::isQuietTime(new DateTimeImmutable('2026-11-13 20:59')));
        self::assertTrue(ProfilePrefs::isQuietTime(new DateTimeImmutable('2026-11-15 12:00')));
    }
}
