<?php

/**
 * (388) Πώς φαίνεται μια σύνδεση: «Browser, Σύστημα» και μισή IP.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Security;

use EnergyCRM\Domain\Security\DeviceLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceLabelTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function agents(): iterable
    {
        yield 'chrome windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/131.0.0.0 Safari/537.36',
            'Chrome, Windows',
            false,
        ];
        yield 'edge windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0',
            'Edge, Windows',
            false,
        ];
        yield 'chrome android' => [
            'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) '
                . 'Chrome/131.0.0.0 Mobile Safari/537.36',
            'Chrome, Android',
            true,
        ];
        yield 'safari iphone' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/18.1 Mobile/15E148 Safari/604.1',
            'Safari, iPhone',
            true,
        ];
        yield 'safari mac' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) '
                . 'Version/18.1 Safari/605.1.15',
            'Safari, macOS',
            false,
        ];
        yield 'firefox linux' => [
            'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
            'Firefox, Linux',
            false,
        ];
    }

    #[DataProvider('agents')]
    public function testItNamesBrowserAndSystem(string $ua, string $label, bool $mobile): void
    {
        self::assertSame($label, DeviceLabel::from($ua));
        self::assertSame($mobile, DeviceLabel::isMobile($ua));
    }

    public function testAnUnknownAgentStillSaysSomething(): void
    {
        self::assertSame('Άγνωστη συσκευή', DeviceLabel::from(''));
        self::assertSame('Άγνωστη συσκευή', DeviceLabel::from('curl/8.5.0'));
    }

    public function testTheIpIsHalfHidden(): void
    {
        self::assertSame('85.74.•••.•••', DeviceLabel::maskIp('85.74.12.200'));
        self::assertSame('2a02:587:•••', DeviceLabel::maskIp('2a02:587:4c2e:1100::1'));
        self::assertSame('', DeviceLabel::maskIp(''));
    }
}
