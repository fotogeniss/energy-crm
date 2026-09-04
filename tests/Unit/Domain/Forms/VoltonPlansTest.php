<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\VoltonPlans;
use PHPUnit\Framework\TestCase;

final class VoltonPlansTest extends TestCase
{
    public function testTheCatalogueCoversAllThreeCategories(): void
    {
        $categories = [];

        foreach (VoltonPlans::all() as $plan) {
            $categories[$plan['category']] = true;
        }

        ksort($categories);

        self::assertSame(['business', 'communal', 'home'], array_keys($categories));
    }

    /**
     * Το κενό που έφερε αυτόν τον κατάλογο: η Volton πουλά και αέριο, αλλά το
     * γενικό seed φτιάχνει πρόγραμμα μόνο για ρεύμα. Αν ο κατάλογος χάσει το
     * αέριο, το κενό επιστρέφει χωρίς να κοκκινίσει τίποτα άλλο.
     */
    public function testBothEnergiesAreCovered(): void
    {
        self::assertNotEmpty(VoltonPlans::forEnergy('gas'));
        self::assertNotEmpty(VoltonPlans::forEnergy('power'));
        self::assertCount(3, VoltonPlans::forEnergy('gas'));
        self::assertSame([], VoltonPlans::forEnergy('mobile'));
    }

    /**
     * Το `code` είναι VARCHAR(32) στον πίνακα `programs`. Ένα μακρύτερο θα
     * κοβόταν σιωπηλά από τη MySQL και το πρόγραμμα δεν θα ξαναβρισκόταν ποτέ
     * από code — ούτε κατά την επανεκτέλεση του migration, που θα το ξαναέβαζε.
     */
    public function testEveryCodeFitsTheColumn(): void
    {
        foreach (VoltonPlans::codes() as $code) {
            self::assertLessThanOrEqual(32, strlen($code), $code . ' > 32 χαρακτήρες');
            self::assertMatchesRegularExpression('/^volton_[a-z0-9_]+$/', $code);
        }
    }

    /**
     * `category` και `price_type` γράφονται αυτούσια σε στήλες που δέχονται
     * συγκεκριμένο σύνολο τιμών. Μια τιμή εκτός συνόλου περνά στη βάση και
     * σπάει αργότερα το φιλτράρισμα της φόρμας, όχι το insert.
     */
    public function testCategoriesAndPriceTypesAreSchemaValues(): void
    {
        foreach (VoltonPlans::all() as $code => $plan) {
            self::assertContains($plan['category'], ['home', 'business', 'communal'], $code);
            self::assertContains($plan['priceType'], ['fixed', 'special', 'variable', 'dynamic'], $code);
            self::assertContains($plan['energyType'], ['power', 'gas'], $code);
        }
    }

    /**
     * Τα κυμαινόμενα και τα ειδικά τιμολογούνται με τύπο πάνω στον ΜΤΑΜ ή στο
     * TTF και αλλάζουν κάθε μήνα. Σταθερή τιμή kWh εκεί θα φαινόταν στο
     * dropdown σαν να είναι η τιμή που πληρώνει ο πελάτης.
     */
    public function testOnlyFixedTariffsCarryARate(): void
    {
        foreach (VoltonPlans::all() as $code => $plan) {
            if ($plan['priceKwh'] !== null) {
                self::assertSame('fixed', $plan['priceType'], $code);
            }
        }

        $plans = VoltonPlans::all();

        self::assertSame(0.135, $plans['volton_blue_flat_18m']['priceKwh']);
        self::assertSame(0.145, $plans['volton_blue_flat']['priceKwh']);
        self::assertSame(0.129, $plans['volton_blue_student']['priceKwh']);
        self::assertNull($plans['volton_stay_win']['priceKwh']);
        self::assertNull($plans['volton_green_eidiko']['priceKwh']);
    }

    /**
     * Τα πάγια όπως διαβάστηκαν από τις σελίδες του παρόχου. Φτάνουν στο
     * dropdown του πωλητή, οπότε ένα τυπογραφικό εδώ είναι τιμή που ειπώθηκε
     * λάθος στο τηλέφωνο.
     */
    public function testTheStandingChargesMatchTheProviderPages(): void
    {
        $plans = VoltonPlans::all();

        self::assertSame(11.90, $plans['volton_blue_flat_18m']['fixedCharge']);
        self::assertSame(14.90, $plans['volton_blue_flat']['fixedCharge']);
        self::assertSame(5.00, $plans['volton_blue_student']['fixedCharge']);
        self::assertSame(0.00, $plans['volton_yellow_zero']['fixedCharge']);
        self::assertSame(6.90, $plans['volton_gas_stay_win']['fixedCharge']);
        self::assertSame(8.90, $plans['volton_gas_stay_win_central']['fixedCharge']);
    }

    /**
     * Το μηδέν και το «δεν μετρήθηκε» δεν είναι το ίδιο πράγμα, και ο μόνος
     * τρόπος να μη γίνουν το ίδιο πράγμα είναι να μείνει το δεύτερο `null`.
     * Το Yellow Zero κοστίζει πραγματικά 0 € πάγιο· τα Γ22/Γ23 του Yellow
     * Simple απλώς δεν διαβάστηκαν από τις σελίδες τους.
     */
    public function testAnUnmeasuredStandingChargeStaysNullNotZero(): void
    {
        $plans = VoltonPlans::all();

        self::assertSame(0.00, $plans['volton_yellow_zero']['fixedCharge']);
        self::assertNull($plans['volton_yellow_simple_g22']['fixedCharge']);
        self::assertNull($plans['volton_green_eidiko_g23']['fixedCharge']);

        self::assertFalse(VoltonPlans::exists('volton_picasso'));
        self::assertFalse(VoltonPlans::exists(''));
    }
}
