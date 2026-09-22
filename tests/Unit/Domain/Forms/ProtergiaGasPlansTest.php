<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\ProtergiaBizPlans;
use EnergyCRM\Domain\Forms\ProtergiaGasPlans;
use EnergyCRM\Domain\Forms\ProtergiaHomePlans;
use PHPUnit\Framework\TestCase;

final class ProtergiaGasPlansTest extends TestCase
{
    private static function dir(): string
    {
        return dirname(__DIR__, 4) . '/assets/forms/protergia/';
    }

    public function testTheTwoTariffsTheAgentSentAreAllHere(): void
    {
        self::assertSame(['protergia_fa_sure', 'protergia_fa_single'], ProtergiaGasPlans::codes());
    }

    /** Κοινός κωδικός με ρεύμα θα έκανε το template_key() να διαλέγει κατά σειρά ελέγχων. */
    public function testNoCodeIsSharedWithThePowerTariffs(): void
    {
        $power = array_merge(ProtergiaHomePlans::codes(), ProtergiaBizPlans::codes());

        self::assertSame([], array_intersect(ProtergiaGasPlans::codes(), $power));
    }

    public function testAPlanPrintsOnTheSheetNamedAfterIt(): void
    {
        foreach (ProtergiaGasPlans::codes() as $code) {
            self::assertSame($code, ProtergiaGasPlans::templateKey($code));
        }

        self::assertSame('', ProtergiaGasPlans::templateKey('protergia_fa'));
        self::assertSame('', ProtergiaGasPlans::templateKey(''));
    }

    /**
     * Λείπει μία σελίδα → το έντυπο τυπώνεται κουτσό χωρίς κανένα σφάλμα,
     * γιατί ο βρόχος του draw_pages() σταματά στο πρώτο κενό. Το Sure έχει
     * 5 σελίδες (αίτηση, τιμές, 3 ΓΟΣ), το Single 4 (αίτηση, τιμές, 2 ΓΟΣ).
     */
    public function testEveryPlanHasAllItsPages(): void
    {
        foreach ([ProtergiaGasPlans::SURE => 5, ProtergiaGasPlans::SINGLE => 4] as $code => $pages) {
            self::assertFileExists(self::dir() . $code . '.json');

            for ($page = 1; $page <= $pages; $page++) {
                self::assertFileExists(self::dir() . $code . '-' . $page . '.jpg');
            }

            self::assertFileDoesNotExist(self::dir() . $code . '-' . ($pages + 1) . '.jpg');
        }
    }

    /**
     * Η σελ. 1 και 2 του Sure είναι pixel προς pixel το `protergia_fa` --
     * ίδιος χάρτης. Μια διόρθωση στο ένα που δεν πέρασε στο άλλο είναι ο
     * τρόπος που τα δύο αρχίζουν να τυπώνουν αλλού.
     */
    public function testSureIsTheOldGasSheet(): void
    {
        self::assertFileEquals(self::dir() . 'protergia_fa.json', self::dir() . ProtergiaGasPlans::SURE . '.json');
    }

    /**
     * Το Single έχει το ίδιο πλέγμα με το Sure, συν τα δύο κουτιά τιμολογίου
     * (Αυτόνομο / Κοινόχρηστο) -- το Sure τα έχει ήδη τυπωμένα.
     */
    public function testSingleIsTheSureGridPlusTheTwoTariffBoxes(): void
    {
        $sure   = $this->map(ProtergiaGasPlans::SURE)['fields'];
        $single = $this->map(ProtergiaGasPlans::SINGLE)['fields'];

        self::assertSame(['fa_aftonomo', 'fa_koinoxristo'], array_keys(array_diff_key($single, $sure)));
        self::assertSame($sure, array_intersect_key($single, $sure));
        self::assertTrue($single['fa_aftonomo']['check']);
        self::assertTrue($single['fa_koinoxristo']['check']);
    }

    /**
     * Το ποσό εγγύησης γράφεται δίπλα στην ετικέτα του «Ποσό εγγύησης (€)»,
     * όχι πάνω στο κουτάκι «Καταβολή με τον 1ο λογαριασμό» (x = 113 ως τώρα).
     */
    public function testTheGuaranteeSitsBesideItsOwnLabel(): void
    {
        foreach (['protergia_fa', ProtergiaGasPlans::SURE, ProtergiaGasPlans::SINGLE] as $code) {
            self::assertLessThan(60.0, $this->map($code)['fields']['poso_eggiisis']['x'], $code);
        }
    }

    /** Μπλε το Sure, κίτρινο το Single -- το φίλτρο της φόρμας. */
    public function testColoursAndPricesMatchTheForms(): void
    {
        $plans = ProtergiaGasPlans::all();

        self::assertSame('fixed', $plans[ProtergiaGasPlans::SURE]['priceType']);
        self::assertSame(9.90, $plans[ProtergiaGasPlans::SURE]['fixedCharge']);
        self::assertSame(0.0419, $plans[ProtergiaGasPlans::SURE]['priceKwh']);
        self::assertSame('variable', $plans[ProtergiaGasPlans::SINGLE]['priceType']);
        self::assertSame(5.00, $plans[ProtergiaGasPlans::SINGLE]['fixedCharge']);
        self::assertNull($plans[ProtergiaGasPlans::SINGLE]['priceKwh']);
    }

    /** @return array{fields: array<string, mixed>} */
    private function map(string $code): array
    {
        $map = json_decode((string) file_get_contents(self::dir() . $code . '.json'), true);

        self::assertIsArray($map);

        return $map;
    }
}
