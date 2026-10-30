<?php

/**
 * (328) Η σειρά των προγραμμάτων για έναν λογαριασμό.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Pricing;

use EnergyCRM\Domain\Pricing\BillComparison;
use PHPUnit\Framework\TestCase;

final class BillComparisonTest extends TestCase
{
    public function testCheapestFirstWithSavingAgainstTodayAndUnpricedApart(): void
    {
        $current = BillComparison::currentAnnual(3770, 0.149, 5.0);
        self::assertSame(621.73, $current);

        $names = [1 => 'Protergia'];
        $out   = BillComparison::rank($this->programs(), $names, 3770, $current, 'power', 'home', '2026-10-30');

        self::assertSame(['Cheap', 'Dear'], array_column($out['ranked'], 'name'));
        self::assertSame('Protergia', $out['ranked'][0]['provider']);
        self::assertSame(round($current - (3770 * 0.13 + 60), 2), $out['ranked'][0]['saving']);
        self::assertLessThan(0, $out['ranked'][1]['saving']);
        self::assertSame([['id' => 3, 'name' => 'Dynamic', 'provider' => 'Protergia']], $out['unpriced']);
    }

    public function testOtherEnergyAndOtherCategoryAreLeftOut(): void
    {
        $names = [1 => 'Protergia'];
        $out   = BillComparison::rank($this->programs(), $names, 3770, null, 'power', 'business', '2026-10-30');

        self::assertSame(['Biz'], array_column($out['ranked'], 'name'));
        self::assertNull($out['ranked'][0]['saving']);

        $gas = BillComparison::rank($this->programs(), [], 8000, null, 'gas', '', '2026-10-30');
        self::assertSame(['Gas'], array_column($gas['ranked'], 'name'));
    }

    public function testAStaleVariablePriceIsFlagged(): void
    {
        $out = BillComparison::rank($this->programs(), [], 3770, null, 'power', 'home', '2026-10-30');
        $dear = array_values(array_filter($out['ranked'], static fn ($r) => $r['name'] === 'Dear'))[0];

        self::assertTrue($dear['stale']);
        self::assertSame('2026-08-12', $dear['price_updated']);
    }

    public function testNoTodayPriceMeansNoCurrentCost(): void
    {
        self::assertNull(BillComparison::currentAnnual(3770, null, 5.0));
        self::assertNull(BillComparison::currentAnnual(0, 0.15, 5.0));
    }

    public function testPicassoSizesBecomeOneRowWithTheRightSize(): void
    {
        $rows = [];

        foreach (['protergia_pic_b_s1', 'protergia_pic_b_m1', 'protergia_pic_b_l3'] as $i => $code) {
            $price  = \EnergyCRM\Domain\Pricing\ProtergiaPrices2026::all()[$code];
            $rows[] = [
                'id' => 10 + $i, 'code' => $code, 'provider_id' => 1, 'name' => $code, 'energy_type' => 'power',
                'category' => 'business', 'price_type' => 'fixed', 'price_kwh' => null, 'fixed_charge' => null,
                'pricing' => json_encode($price['pricing']), 'price_updated' => '2026-10-29',
            ];
        }

        $out = BillComparison::rank($rows, [], 3770, null, 'power', 'business', '2026-10-30');

        self::assertCount(1, $out['ranked']);
        self::assertSame('protergia_pic_b_m1', $out['ranked'][0]['code']);
        self::assertTrue($out['ranked'][0]['fit']['reference_available']);
        self::assertSame('M 99,90', $out['ranked'][0]['fit']['reference_label']);

        // Χωρίς το Medium ενεργό: το πλησιέστερο, και η οθόνη το λέει.
        $out = BillComparison::rank([$rows[0], $rows[2]], [], 3770, null, 'power', 'business', '2026-10-30');
        self::assertSame('protergia_pic_b_s1', $out['ranked'][0]['code']);
        self::assertFalse($out['ranked'][0]['fit']['reference_available']);
        self::assertSame(600.0, $out['ranked'][0]['fit']['settlement']);
    }

    /** @return list<array<string, mixed>> */
    private function programs(): array
    {
        $row = static fn (
            int $id,
            string $name,
            string $energy,
            string $category,
            ?string $price,
            ?string $fixed,
            string $type = 'fixed',
            ?string $updated = '2026-10-29'
        ) => [
            'id' => $id, 'provider_id' => 1, 'name' => $name, 'energy_type' => $energy, 'category' => $category,
            'price_type' => $type, 'price_kwh' => $price, 'fixed_charge' => $fixed, 'pricing' => null,
            'price_updated' => $updated,
        ];

        return [
            $row(1, 'Dear', 'power', 'home', '0.165', '5.00', 'variable', '2026-08-12 00:00:00'),
            $row(2, 'Cheap', 'power', 'home', '0.130', '5.00'),
            $row(3, 'Dynamic', 'power', 'home', null, '9.90', 'dynamic'),
            $row(4, 'Biz', 'power', 'business', '0.2', '0'),
            $row(5, 'Gas', 'gas', 'home', '0.05', '5'),
        ];
    }
}
