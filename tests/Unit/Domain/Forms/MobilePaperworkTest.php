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

    /**
     * Και τα δύο κουτιά απαντώνται, όχι μόνο αυτό που θέλουμε τσεκαρισμένο --
     * ίδιος λόγος με την connectionTicks(): κλειδί που λείπει είναι κλειδί που
     * κρατά άλλος χάρτης, και το `+` δίνει προτεραιότητα μόνο σε όσα υπάρχουν.
     */
    public function testTheMobileBlockAnswersForBothBoxes(): void
    {
        $main = P::comboUserTicks(P::COMBO_USER_MAIN);

        self::assertSame(['xristis_kyrios' => 'X', 'xristis_defterevon' => ''], $main);

        $second = P::comboUserTicks(P::COMBO_USER_SECONDARY);

        self::assertSame(['xristis_kyrios' => '', 'xristis_defterevon' => 'X'], $second);
    }

    /**
     * Το έντυπο έχει δύο ζεύγη κουτιών, ένα ανά μπλοκ πελάτη, και το άρθρο 4
     * τα ορίζει ως τους δύο ρόλους της ίδιας προσφοράς. Η φόρμα κρατά ένα πεδίο
     * ρόλου· το δεύτερο ζεύγος βγαίνει ανεστραμμένο από αυτό.
     */
    public function testTheEnergyBlockMirrorsTheMobileOne(): void
    {
        self::assertSame(
            ['xristis_kyrios_energeias' => '', 'xristis_defterevon_energeias' => 'X'],
            P::energyUserTicks(P::COMBO_USER_MAIN)
        );

        self::assertSame(
            ['xristis_kyrios_energeias' => 'X', 'xristis_defterevon_energeias' => ''],
            P::energyUserTicks(P::COMBO_USER_SECONDARY)
        );
    }

    /**
     * Ο φύλακας του λάθους που διορθώθηκε: ώς τις 04/09/2026 τα ίδια δύο
     * κλειδιά ήταν χαρτογραφημένα και στις δύο σελίδες, οπότε το έντυπο έβγαινε
     * με δύο κύριους χρήστες -- κατάσταση που δεν υπάρχει.
     */
    public function testTheSamePersonIsNeverMainInBothBlocks(): void
    {
        foreach ([P::COMBO_USER_MAIN, P::COMBO_USER_SECONDARY] as $role) {
            $mobile = P::comboUserTicks($role);
            $energy = P::energyUserTicks($role);

            self::assertNotSame(
                $mobile['xristis_kyrios'],
                $energy['xristis_kyrios_energeias'],
                'Ο ρόλος «' . $role . '» βγήκε ίδιος και στα δύο μπλοκ'
            );
        }
    }

    /**
     * Χωρίς δηλωμένο ρόλο δεν μαντεύεται κανένας -- αλλά τα κλειδιά ΕΠΙΣΤΡΕΦΟΝΤΑΙ
     * κενά αντί να λείπουν. Άδειος πίνακας θα άφηνε τα δύο κουτιά σε όποιον
     * άλλο χάρτη τα διεκδικεί· κενή τιμή τα σβήνει ρητά.
     */
    public function testAnUnsetRoleTicksNothingButStillAnswers(): void
    {
        self::assertSame(
            ['xristis_kyrios' => '', 'xristis_defterevon' => ''],
            P::comboUserTicks('')
        );

        self::assertSame(
            ['xristis_kyrios_energeias' => '', 'xristis_defterevon_energeias' => ''],
            P::energyUserTicks('')
        );
    }

    /**
     * Σταδιο 4 (05/09/2026): το ιδιο COMBO, ξεκινωντας απο την ΑΛΛΗ πλευρα.
     *
     * Η `forApplication()` απανταει «τι χαρτια θελει μια αιτηση ORIZON» και
     * περιλαμβανει ΠΑΝΤΑ τη συμβαση κινητης. Μια αιτηση VOLTON δεν ανοιγει
     * συμβαση κινητης -- αυτη ειναι ξεχωριστη, μεταγενεστερη αιτηση, ρητη
     * αποφαση του ιδιοκτητη (04/09/2026) -- αρα δεν μπορει να χρησιμοποιησει
     * την ιδια μεθοδο: θελει ΜΟΝΟ το συνοδευτικο φυλλο του COMBO.
     */
    public function testAnApplicationThatIsNotTheMobileContractGetsOnlyTheComboSheet(): void
    {
        self::assertSame([P::COMBO], P::comboAttachmentFor(P::OFFER_COMBO));

        // Καμια προσφορα, ή η ΑΛΛΗ προσφορα: κανενα συνοδευτικο. Το `family`
        // ειναι δευτερη γραμμη κινητης -- δεν εχει νοημα σε αιτηση ρευματος.
        self::assertSame([], P::comboAttachmentFor(P::OFFER_FAMILY));
        self::assertSame([], P::comboAttachmentFor(P::OFFER_NONE));
        self::assertSame([], P::comboAttachmentFor('ό,τι να ναι'));

        // Και ποτε τη ΣΥΜΒΑΣΗ κινητης -- αυτο ειναι ολο το νοημα.
        self::assertNotContains(P::CONTRACT, P::comboAttachmentFor(P::OFFER_COMBO));
    }

    /**
     * Το `orizon_combo` τυπωνει ΜΟΝΟ «ποιο απο τα τεσσερα» -- η ιδια του η
     * σελιδα δεν εχει ουτε ονομα ουτε τιμη προγραμματος (μετρημενο πανω στο
     * assets/forms/orizon_combo.json, Σταδιο 4). Η `fillValues()` δινει και
     * τα τρια μαζι· εδω χρειαζεται μονο το κουτακι.
     */
    public function testTheTickFieldMatchesWhatFillValuesTicks(): void
    {
        foreach (MobilePlans::codes() as $code) {
            $tick = MobilePlans::tickField($code);

            self::assertNotSame('', $tick, "Το {$code} δεν εχει κουτακι.");
            self::assertSame('X', MobilePlans::fillValues($code, false)[$tick] ?? null, $code);
        }

        // Αγνωστο προγραμμα: κενο, ιδιος κανονας «δεν μαντευω» με την
        // fillValues() -- ο καλων ελεγχει το κενο πριν γραψει 'X' πουθενα.
        self::assertSame('', MobilePlans::tickField('orizon_100gb'));
        self::assertSame('', MobilePlans::tickField(''));
    }
}
