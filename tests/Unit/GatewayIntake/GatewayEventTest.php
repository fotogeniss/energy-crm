<?php

/**
 * Γεγονότα CRM → Call Gateway (449): αριθμός, κλειδί, επαναπροσπάθειες.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\GatewayIntake;

use EnergyCRM\GatewayIntake\Domain\GatewayEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GatewayEventTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string}> */
    public static function phones(): array
    {
        return [
            'όπως τον κρατά το CRM'   => ['306982774563', '+306982774563'],
            '10 ψηφία'                => ['6982774563', '+306982774563'],
            'με +30 και κενά'         => ['+30 698 277 4563', '+306982774563'],
            'με 0030'                 => ['00306982774563', '+306982774563'],
            'σταθερό'                 => ['2101234567', '+302101234567'],
            'ξένος αριθμός'           => ['447911123456', null],
            'κουτσός'                 => ['69827', null],
            'κενό'                    => ['', null],
            'κινητό που δεν υπάρχει'  => ['6082774563', null],
        ];
    }

    #[DataProvider('phones')]
    public function testPhonesBecomeTheGatewayFormat(string $raw, ?string $expected): void
    {
        self::assertSame($expected, GatewayEvent::e164($raw));
    }

    public function testUnknownTypeOrBadPhoneMakesNoEvent(): void
    {
        self::assertNull(GatewayEvent::forPhone('lead.x', '306982774563', 1));
        self::assertNull(GatewayEvent::forPhone(GatewayEvent::OPT_OUT, '123', 1));
    }

    public function testOneKeyPerTypeAndPhone(): void
    {
        $a = GatewayEvent::forPhone(GatewayEvent::OPT_OUT, '306982774563', 1);
        $b = GatewayEvent::forPhone(GatewayEvent::OPT_OUT, '6982774563', 2);
        $c = GatewayEvent::forPhone(GatewayEvent::ERASED, '306982774563', 1);

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotNull($c);
        self::assertSame($a->key(), $b->key());
        self::assertNotSame($a->key(), $c->key());
        self::assertStringNotContainsString('6982774563', $a->key());
    }

    public function testStoredEventComesBackTheSame(): void
    {
        $e = GatewayEvent::forPhone(GatewayEvent::ERASED, '306982774563', 1700000000);
        self::assertNotNull($e);

        $back = GatewayEvent::fromArray($e->toArray());

        self::assertEquals($e, $back);
        self::assertNull(GatewayEvent::fromArray(['type' => 'lead.opt_out']));
    }

    public function testRetryNeverEnds(): void
    {
        self::assertSame(60, GatewayEvent::retryDelay(1));
        self::assertSame(21600, GatewayEvent::retryDelay(6));
        self::assertSame(21600, GatewayEvent::retryDelay(5000));
        self::assertSame(60, GatewayEvent::retryDelay(0));
    }

    public function testOnly400And422AreFinal(): void
    {
        foreach ([400, 422] as $code) {
            self::assertTrue(GatewayEvent::isPermanent($code));
        }
        foreach ([0, 401, 403, 404, 429, 500, 503] as $code) {
            self::assertFalse(GatewayEvent::isPermanent($code), (string) $code);
        }
        self::assertTrue(GatewayEvent::delivered(204));
        self::assertFalse(GatewayEvent::delivered(301));
    }
}
