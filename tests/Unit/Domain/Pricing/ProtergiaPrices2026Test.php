<?php

/**
 * (328) Οι τιμές Protergia πέφτουν σε προγράμματα που υπάρχουν, και κοστολογούνται.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Pricing;

use EnergyCRM\Domain\Forms\ProtergiaBizCatalogue;
use EnergyCRM\Domain\Forms\ProtergiaCatalogue;
use EnergyCRM\Domain\Forms\ProtergiaPicassoPlans;
use EnergyCRM\Domain\Pricing\ProgramPricing;
use EnergyCRM\Domain\Pricing\ProtergiaPrices2026;
use PHPUnit\Framework\TestCase;

final class ProtergiaPrices2026Test extends TestCase
{
    public function testEveryPricedCodeIsACatalogueProgram(): void
    {
        $known = array_merge(
            array_keys(ProtergiaCatalogue::added()),
            array_keys(ProtergiaBizCatalogue::all()),
            array_keys(ProtergiaPicassoPlans::all())
        );

        foreach (array_keys(ProtergiaPrices2026::all()) as $code) {
            self::assertContains($code, $known, $code . ' δεν είναι πρόγραμμα του καταλόγου');
        }
    }

    public function testEveryPriceProducesACost(): void
    {
        foreach (ProtergiaPrices2026::all() as $code => $price) {
            $program = $price;
            $program['pricing'] = $price['pricing'] === null ? null : json_encode($price['pricing']);

            self::assertNotNull(ProgramPricing::annualCost($program, 3000), $code);
        }
    }

    public function testPicassoAndStudentPriceAsTheSmallPackage(): void
    {
        $all = ProtergiaPrices2026::all();

        self::assertSame($all['protergia_pic_h_s1'], $all['protergia_picasso2']);
        // (332) Ίδιες τιμές με το S 39,90, αλλά δική του οικογένεια: μόνο για
        // φοιτητές, και η πρόταση δεν πρέπει να το κρύβει μέσα στα Picasso.
        $student = $all['protergia_picasso2_student']['pricing'];
        self::assertSame('protergia_picasso2_student', $student['family_id']);
        $student['family_id'] = 'protergia_pic_h';
        self::assertSame($all['protergia_pic_h_s1']['pricing'], $student);
        self::assertSame(1325, $all['protergia_pic_h_s1']['pricing']['package_kwh']);
        self::assertSame(21.50, $all['protergia_pic_h_s1']['pricing']['supply_month']);
    }

    /**
     * (371) Οι τιμές και οι ρήτρες των εντύπων 11/2026, γραμμένες ξανά με το
     * χέρι από τη σελ. 2 κάθε αίτησης (ΕΟ.1250-2_307 / _304 / _305).
     */
    public function testTheNovember2026FormsPriceAsPrinted(): void
    {
        $all  = ProtergiaPrices2026::all();
        $fees = ProtergiaPrices2026::exitFees();

        $sure = $all['protergia_value_sure5_12m'];
        self::assertSame(0.187, $sure['price_kwh']);
        self::assertSame(16.90, $sure['fixed_charge']);

        $home = $all['protergia_value_balance2_12m'];
        self::assertSame(14.90, $home['fixed_charge']);
        self::assertSame([[150, 0.154], [null, 0.249]], $home['pricing']['tiers']);

        $biz = $all['protergia_epag1_balance2_12m'];
        self::assertSame(15.90, $biz['fixed_charge']);
        self::assertSame([[250, 0.174], [null, 0.249]], $biz['pricing']['tiers']);

        $home12 = [240, 240, 240, 180, 180, 180, 120, 120, 120, 60, 60, 0];
        self::assertSame($home12, $fees['protergia_value_sure5_12m']);
        self::assertSame($home12, $fees['protergia_value_balance2_12m']);
        $e1 = [300, 300, 300, 225, 225, 225, 150, 150, 150, 75, 75, 0];
        self::assertSame($e1, $fees['protergia_epag1_balance2_12m']);
    }

    public function testExitFeesAreTwelveMonthsEndingAtZeroForKnownPrograms(): void
    {
        $priced = ProtergiaPrices2026::all();

        foreach (ProtergiaPrices2026::exitFees() as $code => $fees) {
            self::assertArrayHasKey($code, $priced, $code);
            self::assertCount(12, $fees, $code);
            self::assertSame(0, $fees[11], $code);
        }
    }
}
