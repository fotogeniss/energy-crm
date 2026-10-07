<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\ProtergiaBizPlans;
use EnergyCRM\Domain\Forms\ProtergiaCatalogue;
use EnergyCRM\Domain\Forms\ProtergiaGasPlans;
use EnergyCRM\Domain\Forms\ProtergiaHomePlans;
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
}
