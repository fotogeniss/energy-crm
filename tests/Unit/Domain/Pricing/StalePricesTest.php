<?php

/**
 * (336) Τιμές κυμαινόμενων που παλιώνουν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Pricing;

use EnergyCRM\Domain\Pricing\StalePrices;
use PHPUnit\Framework\TestCase;

final class StalePricesTest extends TestCase
{
    public function testOnlyVariablePowerAndGasWithOldOrNoPriceAreListed(): void
    {
        $row = static fn (string $name, string $type, ?string $updated, string $energy = 'power') => [
            'name' => $name, 'price_type' => $type, 'price_updated' => $updated, 'energy_type' => $energy,
        ];

        $names = StalePrices::names([
            $row('Παλιό', 'variable', '2026-08-01'),
            $row('Φρέσκο', 'variable', '2026-10-20'),
            $row('Χωρίς τιμή', 'dynamic', null),
            $row('Σταθερό', 'fixed', '2025-01-01'),
            $row('Κινητή', 'variable', null, 'mobile'),
        ], '2026-10-30');

        self::assertSame(['Παλιό', 'Χωρίς τιμή'], $names);
    }

    public function testOnceAWeekUnlessTheListGrows(): void
    {
        self::assertTrue(StalePrices::shouldNotify(null, 0, 2, '2026-10-30'));
        self::assertFalse(StalePrices::shouldNotify('2026-10-28', 2, 2, '2026-10-30'));
        self::assertTrue(StalePrices::shouldNotify('2026-10-28', 2, 3, '2026-10-30'));
        self::assertTrue(StalePrices::shouldNotify('2026-10-23', 2, 2, '2026-10-30'));
        self::assertFalse(StalePrices::shouldNotify(null, 0, 0, '2026-10-30'));
    }

    public function testTheMessageNamesUpToFive(): void
    {
        $msg = StalePrices::message(['A', 'B', 'C', 'D', 'E', 'F', 'G']);

        self::assertSame('7 προγράμματα θέλουν νέα τιμή', $msg['title']);
        self::assertSame('A, B, C, D, E και 2 ακόμα — Πάροχοι & Προγράμματα', $msg['body']);
        self::assertSame('1 πρόγραμμα θέλει νέα τιμή', StalePrices::message(['A'])['title']);
    }
}
