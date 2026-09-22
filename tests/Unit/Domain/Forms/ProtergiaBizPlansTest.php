<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\ProtergiaBizPlans;
use EnergyCRM\Domain\Forms\ProtergiaHomePlans;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtergiaBizPlansTest extends TestCase
{
    /** @return list<array{string}> */
    public static function plans(): array
    {
        return array_map(static fn (string $code): array => [$code], ProtergiaBizPlans::codes());
    }

    public function testTheThreeTariffsTheAgentSentAreAllHere(): void
    {
        self::assertSame(
            ['protergia_epag_sure12', 'protergia_epag_simple2', 'protergia_epag_seasonal'],
            ProtergiaBizPlans::codes()
        );
    }

    /**
     * Ενας κωδικός κοινός με οικιακό θα έκανε το template_key() να διαλέγει
     * φύλλο ανάλογα με το ποιος έλεγχος τρέχει πρώτος.
     */
    public function testNoCodeIsSharedWithTheHomeTariffs(): void
    {
        self::assertSame([], array_intersect(ProtergiaBizPlans::codes(), ProtergiaHomePlans::codes()));
    }

    #[DataProvider('plans')]
    public function testAPlanPrintsOnTheSheetNamedAfterIt(string $code): void
    {
        self::assertSame($code, ProtergiaBizPlans::templateKey($code));
    }

    /**
     * Λείπει μία σελίδα → το έντυπο τυπώνεται κουτσό χωρίς κανένα σφάλμα,
     * γιατί ο βρόχος του draw_pages() σταματά στο πρώτο κενό.
     */
    #[DataProvider('plans')]
    public function testEveryPlanHasItsBundledTemplate(string $code): void
    {
        $dir = dirname(__DIR__, 4) . '/assets/forms/protergia/';

        self::assertFileExists($dir . $code . '.json');

        for ($page = 1; $page <= 6; $page++) {
            self::assertFileExists($dir . $code . '-' . $page . '.jpg');
        }

        self::assertFileDoesNotExist($dir . $code . '-7.jpg');
    }

    /**
     * Ο,τι είναι μόνο στο επαγγελματικό φύλλο -- νόμιμος εκπρόσωπος, ΚΑΔ,
     * κατηγορία «Επαγγελματική» -- πρέπει να έχει θέση. Και το «Οικιακή» ΔΕΝ
     * πρέπει: το φύλλο δεν έχει τέτοιο κουτί, θα τυπωνόταν Χ στο κενό.
     */
    #[DataProvider('plans')]
    public function testTheMapCarriesTheBusinessOnlyBoxes(string $code): void
    {
        $fields = $this->map($code)['fields'];

        $required = [
            'onomateponymo_ekprosopou',
            'kad',
            'katigoria_paroxis_epaggelmatiki',
            'synainesi_omilou_nai',
            'synainesi_erevnas_oxi',
            'poso_eggiisis',
        ];

        foreach ($required as $key) {
            self::assertArrayHasKey($key, $fields, "{$code}: λείπει το {$key}");
        }

        self::assertArrayNotHasKey('katigoria_paroxis_oikiaki', $fields);
    }

    /**
     * Η σελ. 1 και η σελ. 3 είναι το ίδιο φύλλο και στα τρία έντυπα (template
     * matching, απόκλιση 0). Μόνο η σελ. 2 -- ο πίνακας τιμών της Protergia --
     * διαφέρει. Μια διόρθωση στη σελ. 1 του ενός που δεν πέρασε στα άλλα είναι
     * ακριβώς ο τρόπος που δύο από τα τρία αρχίζουν να τυπώνουν αλλού.
     */
    public function testPagesOneAndThreeAreTheSameInAllThree(): void
    {
        $strip = static function (array $map): array {
            $out = [];

            foreach ($map['fields'] as $key => $entry) {
                $list = isset($entry[0]) ? $entry : [$entry];
                $kept = array_values(array_filter($list, static fn (array $e): bool => $e['page'] !== 2));

                if ($kept !== []) {
                    $out[$key] = $kept;
                }
            }

            ksort($out);

            return $out;
        };

        $sure = $strip($this->map(ProtergiaBizPlans::SURE_12));

        self::assertSame($sure, $strip($this->map(ProtergiaBizPlans::SIMPLE_2)));
        self::assertSame($sure, $strip($this->map(ProtergiaBizPlans::SEASONAL)));
    }

    /** Simple και Seasonal έχουν και ίδια σελ. 2 (ίδιες γραμμές στον πίνακα). */
    public function testSimpleAndSeasonalShareOneMap(): void
    {
        $dir = dirname(__DIR__, 4) . '/assets/forms/protergia/';

        self::assertFileEquals(
            $dir . ProtergiaBizPlans::SIMPLE_2 . '.json',
            $dir . ProtergiaBizPlans::SEASONAL . '.json'
        );
    }

    /**
     * Χρώμα όπως το έστειλε ο συνεργάτης: μπλε το Sure, κίτρινα τα άλλα δύο.
     * Είναι το φίλτρο της φόρμας -- λάθος εδώ = το πρόγραμμα «δεν υπάρχει».
     */
    public function testColoursMatchWhatTheAgentWasTold(): void
    {
        $plans = ProtergiaBizPlans::all();

        self::assertSame('fixed', $plans[ProtergiaBizPlans::SURE_12]['priceType']);
        self::assertSame('variable', $plans[ProtergiaBizPlans::SIMPLE_2]['priceType']);
        self::assertSame('variable', $plans[ProtergiaBizPlans::SEASONAL]['priceType']);
    }

    /**
     * Οι τιμές όπως τυπώνονται στη σελ. 2. Το Seasonal δεν έχει ούτε ενιαίο
     * πάγιο ούτε ενιαία τιμή (αλλάζουν ανά εξάμηνο) -- ένα νούμερο θα
     * εμφανιζόταν στο dropdown σαν να ισχύει όλο τον χρόνο.
     */
    public function testPricesMatchTheForms(): void
    {
        $plans = ProtergiaBizPlans::all();

        self::assertSame(13.90, $plans[ProtergiaBizPlans::SURE_12]['fixedCharge']);
        self::assertSame(0.269, $plans[ProtergiaBizPlans::SURE_12]['priceKwh']);
        self::assertSame(5.00, $plans[ProtergiaBizPlans::SIMPLE_2]['fixedCharge']);
        self::assertNull($plans[ProtergiaBizPlans::SIMPLE_2]['priceKwh']);
        self::assertNull($plans[ProtergiaBizPlans::SEASONAL]['fixedCharge']);
        self::assertNull($plans[ProtergiaBizPlans::SEASONAL]['priceKwh']);
    }

    public function testAnUnknownPlanHasNoTemplate(): void
    {
        self::assertSame('', ProtergiaBizPlans::templateKey('protergia_oik_sure12'));
        self::assertSame('', ProtergiaBizPlans::templateKey(''));
    }

    /** @return array{fields: array<string, mixed>} */
    private function map(string $code): array
    {
        $path = dirname(__DIR__, 4) . '/assets/forms/protergia/' . $code . '.json';
        $map  = json_decode((string) file_get_contents($path), true);

        self::assertIsArray($map);

        return $map;
    }
}
