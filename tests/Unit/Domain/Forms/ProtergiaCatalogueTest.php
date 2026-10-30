<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\ProtergiaBizPlans;
use EnergyCRM\Domain\Forms\ProtergiaBizSheets;
use EnergyCRM\Domain\Forms\ProtergiaCatalogue;
use EnergyCRM\Domain\Forms\ProtergiaGasPlans;
use EnergyCRM\Domain\Forms\ProtergiaGasSheets;
use EnergyCRM\Domain\Forms\ProtergiaHomePlans;
use EnergyCRM\Domain\Forms\ProtergiaHomeSheets;
use EnergyCRM\Domain\Forms\ProtergiaPicassoPlans;
use PHPUnit\Framework\TestCase;

final class ProtergiaCatalogueTest extends TestCase
{
    /** Η λίστα του ιδιοκτήτη: 10 οικιακά ρεύματος (μαζί με το Lite 2.0) και 4 αερίου. */
    public function testTheListHasTenPowerAndFourGasPlans(): void
    {
        $power = 0;
        $gas   = 0;

        foreach (ProtergiaCatalogue::added() as $plan) {
            $plan['energy'] === 'power' ? $power++ : $gas++;
        }

        // 9 νέα + Power+Gas στο ρεύμα, + το Lite 2.0 που υπήρχε ήδη.
        self::assertSame(10, $power);
        self::assertSame(1, count(ProtergiaCatalogue::KEPT));
        self::assertSame(4, $gas);
    }

    /**
     * Τα νέα δεν έχουν δικό τους φύλλο ακόμα: ο κωδικός τους δεν πρέπει να
     * είναι κλειδί εντύπου, αλλιώς θα τυπωνόταν λάθος τιμολόγιο.
     */
    public function testNewPlansFallBackToTheGeneralForm(): void
    {
        foreach (array_keys(ProtergiaCatalogue::added()) as $code) {
            self::assertFalse(ProtergiaHomePlans::exists($code), $code);
            self::assertFalse(ProtergiaGasPlans::exists($code), $code);
            self::assertFalse(ProtergiaBizPlans::exists($code), $code);
            self::assertLessThanOrEqual(32, strlen($code), $code . ' δεν χωράει στη στήλη code');
        }
    }

    /** Τα αποσυρμένα κρατάνε το φύλλο τους, για τις παλιές αιτήσεις. */
    public function testRetiredPlansKeepTheirForm(): void
    {
        foreach (ProtergiaCatalogue::RETIRED as $code) {
            self::assertTrue(ProtergiaHomePlans::exists($code) || ProtergiaGasPlans::exists($code), $code);
            self::assertTrue(ProtergiaCatalogue::isRetired($code));
        }

        self::assertFalse(ProtergiaCatalogue::isRetired(ProtergiaHomePlans::LITE_2));
    }

    public function testPriceTypesAreOnesTheFormKnows(): void
    {
        foreach (ProtergiaCatalogue::added() as $code => $plan) {
            self::assertContains($plan['priceType'], ['', 'fixed', 'variable', 'special', 'dynamic'], $code);
        }
    }

    /**
     * (324)/(325) Όσα περιμένουν έντυπο είναι προγράμματα του καταλόγου ή
     * οικιακά Picasso, χωρίς δικό τους φύλλο ακόμα -- όταν αποκτήσουν,
     * πρέπει να βγουν από τη λίστα.
     */
    public function testPlansAwaitingASheetAreCataloguePlansWithoutOne(): void
    {
        $picasso = array_keys(ProtergiaPicassoPlans::all());

        foreach (ProtergiaCatalogue::AWAITING_SHEET as $code) {
            self::assertTrue(
                array_key_exists($code, ProtergiaCatalogue::added()) || in_array($code, $picasso, true),
                $code
            );
            self::assertSame('', ProtergiaGasSheets::templateKey($code), $code . ' έχει πια φύλλο');
            self::assertSame('', ProtergiaBizSheets::templateKey($code), $code . ' έχει πια φύλλο');
            self::assertSame('', ProtergiaHomeSheets::templateKey($code), $code . ' έχει πια φύλλο');
            self::assertFalse(ProtergiaCatalogue::isRetired($code), $code);
        }

        self::assertNotContains('protergia_gas_sure2', ProtergiaCatalogue::AWAITING_SHEET, 'ήρθε 30/10 (337)');
        self::assertContains('protergia_value_bright2', ProtergiaCatalogue::AWAITING_SHEET);
        self::assertNotContains('protergia_pic_h_s1', ProtergiaCatalogue::AWAITING_SHEET, 'το S 39,90 έχει φύλλο');
    }

    /** (325) Protergia 29/10: το Power+Gas δεν είναι πρόγραμμα, ούτε περιμένει έντυπο. */
    public function testPowerPlusGasIsNotAProgram(): void
    {
        self::assertSame(['protergia_power_gas_fa', 'protergia_power_gas_he'], ProtergiaCatalogue::NOT_A_PROGRAM);

        foreach (ProtergiaCatalogue::NOT_A_PROGRAM as $code) {
            self::assertArrayHasKey($code, ProtergiaCatalogue::added());
            self::assertNotContains($code, ProtergiaCatalogue::AWAITING_SHEET);
        }
    }
}
