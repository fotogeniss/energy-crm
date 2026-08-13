<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\MobilePaperwork as P;
use EnergyCRM\Domain\Forms\MobilePlans;
use PHPUnit\Framework\TestCase;

final class MobilePaperworkTest extends TestCase
{
    public function testAPlainApplicationIsJustTheContract(): void
    {
        self::assertSame([P::CONTRACT], P::forApplication(P::REQUEST_NEW_NUMBER, P::OFFER_NONE));
    }

    public function testPortabilityAddsThePortingDesksOwnSheet(): void
    {
        self::assertSame(
            [P::CONTRACT, P::PORTABILITY],
            P::forApplication(P::REQUEST_PORTABILITY, P::OFFER_NONE)
        );
    }

    /**
     * The two discounts are alternative routes to the same price, so printing
     * both would tell the provider the customer qualified twice.
     */
    public function testOnlyOneCombinedOfferSheetIsEverPrinted(): void
    {
        self::assertSame(
            [P::CONTRACT, P::FAMILY],
            P::forApplication(P::REQUEST_NEW_NUMBER, P::OFFER_FAMILY)
        );
        self::assertSame(
            [P::CONTRACT, P::COMBO],
            P::forApplication(P::REQUEST_NEW_NUMBER, P::OFFER_COMBO)
        );
    }

    public function testPortabilityAndACombinedOfferStack(): void
    {
        self::assertSame(
            [P::CONTRACT, P::PORTABILITY, P::COMBO],
            P::forApplication(P::REQUEST_PORTABILITY, P::OFFER_COMBO)
        );
    }

    /** An unrecognised offer must print nothing extra, not guess. */
    public function testAnUnknownOfferAddsNoSheet(): void
    {
        self::assertSame([P::CONTRACT], P::forApplication(P::REQUEST_NEW_NUMBER, 'both'));
        self::assertFalse(P::isOfferValid('both'));
        self::assertTrue(P::isOfferValid(P::OFFER_NONE));
    }

    /**
     * The screen offers two choices where the paper has three boxes.
     *
     * These assertions changed on 2026-08-12, and the reason is the point of
     * the test. The method used to return only the key it wanted ticked; the
     * electricity fill map writes `energopoiisi_ananeosi` from
     * `activation_type`, and the two are merged with `+`, which lets the left
     * side win only for keys it actually contains. A mobile contract carrying
     * activation_type 'renewal' therefore printed ΦΟΡΗΤΟΤΗΤΑ **and**
     * ΑΝΑΝΕΩΣΗ — a signed application saying two contradictory things.
     *
     * So the shape asserted here is the whole group, empty values included.
     * The empty ones are the load-bearing part.
     */
    public function testTheConnectionBoxesAreAnsweredAsAGroup(): void
    {
        self::assertSame(
            [
                'energopoiisi_foritotita'  => '',
                'energopoiisi_nea_syndesi' => 'X',
                'energopoiisi_ananeosi'    => '',
            ],
            P::connectionTicks(P::REQUEST_NEW_NUMBER)
        );

        self::assertSame(
            [
                'energopoiisi_foritotita'  => 'X',
                'energopoiisi_nea_syndesi' => '',
                'energopoiisi_ananeosi'    => '',
            ],
            P::connectionTicks(P::REQUEST_PORTABILITY)
        );
    }

    /**
     * ΑΝΑΝΕΩΣΗ is never ticked, whatever arrives.
     *
     * Its own test because it is the one the CRM cannot produce and the paper
     * can: no screen offers it, so any X in that box came from somewhere that
     * was not asked.
     */
    public function testRenewalIsNeverTicked(): void
    {
        foreach ([P::REQUEST_NEW_NUMBER, P::REQUEST_PORTABILITY, '', 'renewal', 'ό,τι να ναι'] as $requestType) {
            self::assertSame('', P::connectionTicks($requestType)['energopoiisi_ananeosi']);
        }
    }

    /** An unrecognised request type ticks nothing, rather than guessing. */
    public function testAnUnknownRequestTypeTicksNothing(): void
    {
        self::assertSame(['', '', ''], array_values(P::connectionTicks('')));
    }

    /**
     * The prices printed on the plain contract, checked against the provider's
     * own table. A wrong figure here is a wrong figure on a signed document.
     */
    public function testTheContractPrintsTheUndiscountedPrices(): void
    {
        $expected = [
            MobilePlans::P_5GB => '15 €', MobilePlans::P_10GB_5GB => '18 €',
            MobilePlans::P_40GB => '23 €', MobilePlans::P_UNLIMITED => '26 €',
        ];

        foreach ($expected as $plan => $price) {
            self::assertSame($price, MobilePlans::fillValues($plan, false)['timi_prosforas']);
        }
    }

    /** Συνδυαστική and COMBO print the same, lower figure. */
    public function testACombinedOfferPrintsTheDiscountedPrices(): void
    {
        $expected = [
            MobilePlans::P_5GB => '13 €', MobilePlans::P_10GB_5GB => '16 €',
            MobilePlans::P_40GB => '19 €', MobilePlans::P_UNLIMITED => '23 €',
        ];

        foreach ($expected as $plan => $price) {
            self::assertSame($price, MobilePlans::fillValues($plan, true)['timi_prosforas']);
            self::assertSame($price, MobilePlans::fillValues($plan, true)['timi_prosforas']);
        }
    }

    public function testTheChosenPlanIsTheOnlyOneTicked(): void
    {
        $values = MobilePlans::fillValues(MobilePlans::P_40GB, false);

        self::assertSame('X', $values['programma_40gb']);
        foreach (['programma_5gb', 'programma_10gb_5gb', 'programma_unlimited'] as $other) {
            self::assertArrayNotHasKey($other, $values);
        }
    }

    public function testAnUnknownPlanPrintsNothingRatherThanAWrongPrice(): void
    {
        self::assertSame([], MobilePlans::fillValues('orizon_100gb', false));
        self::assertSame(0, MobilePlans::monthlyPrice('orizon_100gb', false));
        self::assertFalse(MobilePlans::exists('orizon_100gb'));
    }

    public function testTheDropdownOffersExactlyTheFourPlans(): void
    {
        self::assertSame(
            ['orizon 5GB', 'orizon 10GB + 5GB', 'orizon 40GB', 'orizon unlimited'],
            array_values(MobilePlans::options())
        );
    }

    /**
     * The screen's read-only price boxes and the printed form must agree —
     * both are read from the same table, but a typo in one of the two
     * projections would show the agent a number the paper does not print.
     */
    public function testThePricingTableAgreesWithWhatPrints(): void
    {
        foreach (MobilePlans::codes() as $code) {
            $pricing = MobilePlans::pricingTable()[$code];

            $plain    = MobilePlans::fillValues($code, false);
            $combined = MobilePlans::fillValues($code, true);

            self::assertSame($pricing['offer'], $plain['timi_prosforas']);
            self::assertSame($pricing['after'], $plain['pagio_meta_ti_prosfora']);
            self::assertSame($pricing['offerCombined'], $combined['timi_prosforas']);
            self::assertSame($pricing['afterCombined'], $combined['pagio_meta_ti_prosfora']);
            self::assertSame($pricing['list'], $plain['arxiki_timi_pagiou']);
        }
    }

    public function testThePricingTableHasNothingForAnUnknownPlan(): void
    {
        self::assertArrayNotHasKey('orizon_100gb', MobilePlans::pricingTable());
    }
}
