<?php

/**
 * (332) Picasso: εκκαθάριση με Πακέτο Αναφοράς (ΓΟΣ, Ειδικός Όρος 2).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Pricing;

use EnergyCRM\Domain\Pricing\PackageFit;
use EnergyCRM\Domain\Pricing\ProgramPricing;
use EnergyCRM\Domain\Pricing\ProtergiaPrices2026;
use PHPUnit\Framework\TestCase;

final class PackageFitTest extends TestCase
{
    public function testTheReferenceIsTheLargestPackageThatFits(): void
    {
        $family = $this->rule('protergia_pic_h_s1')['family'];

        self::assertSame('protergia_pic_h_m1', PackageFit::reference($family, 3770)['code']);
        self::assertSame('protergia_pic_h_m1', PackageFit::reference($family, 3550)['code']);
        self::assertSame('protergia_pic_h_s1', PackageFit::reference($family, 900)['code']);
        self::assertSame('protergia_pic_h_l3', PackageFit::reference($family, 50000)['code']);
    }

    public function testTheYearCostsTheSameWhicheverSizeIsSigned(): void
    {
        $small = ProgramPricing::annualCost($this->program('protergia_pic_h_s1'), 3770);
        $large = ProgramPricing::annualCost($this->program('protergia_pic_h_l3'), 3770);

        // Medium 3.550: 12 × 57,27 + 220 × 0,099
        self::assertSame(709.02, $small);
        self::assertSame($small, $large);
    }

    public function testBelowTheSmallestPackageTheShortfallIsCredited(): void
    {
        // 12 × 21,50 − (1325 − 900) × 0,174
        $cost = ProgramPricing::annualCost($this->program('protergia_pic_h_s1'), 900);
        self::assertSame(round(258 - 425 * 0.174, 2), $cost);
    }

    public function testTheWrongSizeIsSettledAtTheEndOfTheYear(): void
    {
        $fit = PackageFit::fit($this->rule('protergia_pic_b_s1'), 3770);

        self::assertFalse($fit['fits']);
        self::assertSame('M 99,90', $fit['reference']['label']);
        self::assertSame(600.0, $fit['settlement']);

        $big = PackageFit::fit($this->rule('protergia_pic_b_m2'), 3770);
        self::assertSame(-300.0, $big['settlement']);

        self::assertTrue(PackageFit::fit($this->rule('protergia_pic_b_m1'), 3770)['fits']);
    }

    public function testAPackageWithoutAFamilyKeepsTheOldRule(): void
    {
        $program = [
            'pricing' => ['kind' => 'package', 'package_kwh' => 1325, 'supply_month' => 21.50, 'excess_kwh' => 0.099],
        ];

        self::assertSame(258.0, ProgramPricing::annualCost($program, 900));
    }

    /** @return array<string, mixed> */
    private function rule(string $code): array
    {
        return ProgramPricing::rule($this->program($code));
    }

    /** @return array<string, mixed> */
    private function program(string $code): array
    {
        return ['pricing' => json_encode(ProtergiaPrices2026::all()[$code]['pricing'])];
    }
}
