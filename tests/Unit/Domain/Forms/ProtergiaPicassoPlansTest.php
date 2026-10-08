<?php

/**
 * Τα Picasso S/M/L της Protergia (309).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\ProtergiaBizPlans;
use EnergyCRM\Domain\Forms\ProtergiaGasPlans;
use EnergyCRM\Domain\Forms\ProtergiaHomePlans;
use EnergyCRM\Domain\Forms\ProtergiaPicassoPlans;
use PHPUnit\Framework\TestCase;

final class ProtergiaPicassoPlansTest extends TestCase
{
    /** Η λίστα του ιδιοκτήτη: 9 οικιακά και 9 επαγγελματικά. */
    public function testThereAreNineHomeAndNineBusinessPlans(): void
    {
        $count = ['home' => 0, 'business' => 0];

        foreach (ProtergiaPicassoPlans::all() as $plan) {
            $count[$plan['category']]++;
        }

        self::assertSame(['home' => 9, 'business' => 9], $count);
    }

    public function testThePriceIsInTheNameSoTheThreePlansOfASizeAreTellApart(): void
    {
        $labels = array_column(ProtergiaPicassoPlans::all(), 'label');

        self::assertSame(count($labels), count(array_unique($labels)));
        self::assertContains('Protergia Picasso S 39,90 €', $labels);
        self::assertContains('Protergia Picasso L 479,90 €', $labels);
        self::assertContains('Protergia Picasso L 444,90 € — Επαγγελματικό', $labels);
    }

    /** Χωρίς δικό τους φύλλο, αλλιώς θα τυπωνόταν λάθος τιμολόγιο. */
    public function testNoCodeIsAFormKeyAndAllFitTheColumn(): void
    {
        foreach (array_keys(ProtergiaPicassoPlans::all()) as $code) {
            self::assertFalse(ProtergiaHomePlans::exists($code), $code);
            self::assertFalse(ProtergiaGasPlans::exists($code), $code);
            self::assertFalse(ProtergiaBizPlans::exists($code), $code);
            self::assertLessThanOrEqual(32, strlen($code), $code);
        }
    }
}
