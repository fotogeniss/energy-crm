<?php

/**
 * (321) Τα φύλλα αερίου Protergia του Οκτωβρίου 2026 και οι κανόνες της ίδιας
 * της Protergia για την εκτύπωσή τους (email 21-22/10).
 *
 * Οι θέσεις των κουτιών είναι ΜΕΤΡΗΜΕΝΕΣ από τα διανυσματικά σχήματα των PDF
 * που έστειλε η Protergia (tools/protergia-gas-2026-maps.py)· εδώ γράφονται
 * ξανά με το χέρι, ώστε μια μετακίνηση στον χάρτη να μη «συμφωνεί» με τον
 * εαυτό της.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\ProtergiaGasSheets;
use EnergyCRM\Domain\Forms\ProviderFormFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtergiaGasSheetsTest extends TestCase
{
    private const HOME = ['protergia_fa_oik_standard', 'protergia_fa_oik_flex'];
    private const BIZ  = ['protergia_fa_epag_standard', 'protergia_fa_epag_flex'];

    public function testEachVariableTariffPrintsItsOwnSheet(): void
    {
        self::assertSame('protergia_fa_oik_flex', ProtergiaGasSheets::templateKey('protergia_gas_flex'));
        self::assertSame('protergia_fa_oik_standard', ProtergiaGasSheets::templateKey('protergia_gas_standard'));
        self::assertSame('protergia_fa_epag_flex', ProtergiaGasSheets::templateKey('protergia_epag_gas_flex'));
        self::assertSame('protergia_fa_epag_standard', ProtergiaGasSheets::templateKey('protergia_epag_gas_standard'));

        // Χωρίς δικό τους έντυπο ακόμα: μένουν στο protergia_fa.
        self::assertSame('', ProtergiaGasSheets::templateKey('protergia_gas_sure2'));
        self::assertSame('', ProtergiaGasSheets::templateKey('protergia_power_gas_fa'));
    }

    /** @return iterable<string, array{string, bool, int}> */
    public static function sheets(): iterable
    {
        yield 'οικιακό Standard' => ['protergia_fa_oik_standard', true, 2];
        yield 'οικιακό Flex' => ['protergia_fa_oik_flex', true, 3];
        yield 'επαγγελματικό Standard' => ['protergia_fa_epag_standard', false, 2];
        yield 'επαγγελματικό Flex' => ['protergia_fa_epag_flex', false, 3];
    }

    #[DataProvider('sheets')]
    public function testEveryPageIsThereAndTheLastOneIsSigned(string $tpl, bool $home, int $pages): void
    {
        for ($p = 1; $p <= $pages; $p++) {
            self::assertFileExists(self::dir() . "{$tpl}-{$p}.jpg");
        }
        self::assertFileDoesNotExist(self::dir() . $tpl . '-' . ($pages + 1) . '.jpg');

        $map = $this->map($tpl);
        self::assertCount(1, $map['sigs']);
        self::assertSame($pages, $map['sigs'][0]['page']);
        // Η υπογραφή ακουμπά τη γραμμή «Υπογραφή / Σφραγίδα Πελάτη».
        $line = $home ? 315.1 : 316.9;
        self::assertEqualsWithDelta($line, $map['sigs'][0]['y'] + $map['sigs'][0]['fit_h'], 0.5);
        self::assertSame($pages, $map['fields']['nomimos_ekprosopos_ypografi']['page']);
    }

    #[DataProvider('sheets')]
    public function testTheHkaspIsPrintedOnceAndThePowerSupplyHasItsOwnBox(string $tpl): void
    {
        $f = $this->map($tpl)['fields'];

        self::assertArrayHasKey('hkasp', $f);
        self::assertArrayHasKey('fa_arithmos_paroxis_revmatos', $f);
        self::assertArrayNotHasKey('arithmos_paroxis', $f, 'ο ΗΚΑΣΠ ξανά στο «Αριθμός παροχής» του ρεύματος');
    }

    public function testTheOldGasSheetsNoLongerRepeatTheHkaspEither(): void
    {
        foreach (['protergia_fa', 'protergia_fa_sure', 'protergia_fa_single'] as $tpl) {
            self::assertArrayNotHasKey('arithmos_paroxis', $this->map($tpl)['fields'], $tpl);
            self::assertArrayHasKey('fa_arithmos_paroxis_revmatos', $this->map($tpl)['fields'], $tpl);
        }
    }

    /** Ίδια διάταξη στο Flex και στο Standard: ίδιες θέσεις, εκτός της σελίδας υπογραφής. */
    public function testFlexAndStandardShareTheirLayout(): void
    {
        foreach ([self::HOME, self::BIZ] as [$standard, $flex]) {
            $a = $this->map($standard)['fields'];
            $b = $this->map($flex)['fields'];
            unset($a['nomimos_ekprosopos_ypografi'], $b['nomimos_ekprosopos_ypografi']);
            self::assertSame($a, $b, "{$standard} / {$flex}");
        }
    }

    /** Το Χ (DejaVu, 0,2416 mm πλάτος και 0,257 mm ύψος ανά pt) μέσα στο κουτί του. */
    #[DataProvider('sheets')]
    public function testEveryMarkSitsInsideItsBox(string $tpl, bool $home): void
    {
        $boxes = $home
            ? [
                'idiotita_idioktitis' => [[45.0, 52.5, 66.8, 70.3]],
                'idiotita_misthotis' => [[94.3, 101.8, 66.8, 70.3]],
                'pliromi_pagia_entoli' => [[138.5, 146.0, 95.3, 98.8]],
                'fa_aftonomo' => [[30.9, 38.4, 162.5, 165.9]],
                'fa_koinoxristo' => [[81.4, 88.9, 162.5, 165.9]],
                'fa_eggiisi_1os_logariasmos' => [[128.5, 136.0, 173.4, 176.8]],
                'synainesi_omilou_nai' => [[89.8, 93.5, 222.7, 224.4]],
                'synainesi_omilou_oxi' => [[97.9, 101.5, 222.7, 224.4]],
                'synainesi_erevnas_nai' => [[174.8, 178.5, 226.7, 228.3]],
                'synainesi_erevnas_oxi' => [[187.9, 191.5, 226.7, 228.3]],
                'fa_logariasmos_email' => [[174.5, 178.1, 234.5, 236.1]],
                // Ζ΄ (υπαναχώρηση) και ΙΑ΄ (ιδιοκτήτης / ΗΚΑΣΠ)
                'fa_oros_panta' => [[198.5, 202.2, 228.6, 230.2], [177.3, 181.0, 240.4, 242.0]],
            ]
            : [
                'idiotita_idioktitis' => [[46.4, 53.9, 75.7, 79.2]],
                'idiotita_misthotis' => [[138.6, 146.1, 75.7, 79.2]],
                'pliromi_pagia_entoli' => [[139.5, 147.0, 113.5, 116.9]],
                'fa_emporiko' => [[26.4, 33.9, 184.4, 187.9]],
                'fa_eggiisi_1os_logariasmos' => [[128.9, 136.4, 197.5, 200.9]],
                'synainesi_omilou_nai' => [[89.8, 93.5, 247.1, 248.8]],
                'synainesi_omilou_oxi' => [[97.9, 101.5, 247.1, 248.8]],
                'synainesi_erevnas_nai' => [[174.8, 178.5, 251.7, 253.4]],
                'synainesi_erevnas_oxi' => [[187.9, 191.5, 251.7, 253.4]],
                'fa_logariasmos_email' => [[174.5, 178.1, 258.5, 260.2]],
                // Ι΄ (ιδιοκτήτης / ΗΚΑΣΠ) και ΙΑ΄ (προσωπικά δεδομένα)
                'fa_oros_panta' => [[176.9, 180.5, 265.4, 267.1], [129.2, 132.9, 267.7, 269.4]],
            ];

        $f = $this->map($tpl)['fields'];

        foreach ($boxes as $key => $list) {
            $places = isset($f[$key][0]) ? $f[$key] : [$f[$key]];
            self::assertCount(count($list), $places, "{$tpl}:{$key}");

            foreach ($list as $i => [$x0, $x1, $y0, $y1]) {
                $p    = $places[$i];
                $size = (float) ($p['size'] ?? 10);
                $base = $p['y'] + 2.5;

                self::assertTrue($p['check'], "{$tpl}:{$key}");
                self::assertGreaterThanOrEqual($x0, $p['x'], "{$tpl}:{$key} αριστερά");
                self::assertLessThanOrEqual($x1, $p['x'] + 0.2416 * $size, "{$tpl}:{$key} δεξιά");
                self::assertGreaterThanOrEqual($y0 - 0.2, $base - 0.257 * $size, "{$tpl}:{$key} πάνω");
                self::assertLessThanOrEqual($y1 + 0.2, $base, "{$tpl}:{$key} κάτω");
            }
        }

        if ($home) {
            self::assertArrayNotHasKey('fa_emporiko', $f);
        } else {
            self::assertArrayNotHasKey('fa_aftonomo', $f);
            self::assertArrayNotHasKey('fa_koinoxristo', $f);
            self::assertArrayHasKey('kad', $f);
            self::assertArrayHasKey('gemi', $f);
            self::assertArrayHasKey('onomateponymo_ekprosopou', $f);
        }
    }

    /** Κάθε κείμενο σταματά πριν από την επόμενη ετικέτα και πριν από το περιθώριο. */
    #[DataProvider('sheets')]
    public function testTextNeverRunsPastTheNextLabel(string $tpl): void
    {
        foreach ($this->map($tpl)['fields'] as $key => $places) {
            foreach (isset($places[0]) ? $places : [$places] as $p) {
                if (! empty($p['check'])) {
                    continue;
                }
                self::assertGreaterThan(0, $p['maxw'] ?? 0, "{$tpl}:{$key} χωρίς maxw");
                self::assertLessThanOrEqual(200.0, $p['x'] + $p['maxw'], "{$tpl}:{$key}");
            }
        }
    }

    /** Ό,τι γεμίζει το έντυπο το ξέρει και η φόρμα: κανένα κλειδί χωρίς πηγή. */
    #[DataProvider('sheets')]
    public function testTheFormKnowsWhatEverySheetNeeds(string $tpl): void
    {
        $inputs = ProviderFormFields::forTemplate($tpl, dirname(__DIR__, 4) . '/assets/forms/');

        self::assertArrayHasKey('gas_meter_type', $inputs);
        self::assertArrayHasKey('gas_power_supply_number', $inputs);
        self::assertArrayHasKey('payment_method', $inputs);
        self::assertArrayHasKey('bill_delivery', $inputs);
    }

    public function testRemarksAreTheProgramAndThePromotionButNeverTheSocialTariff(): void
    {
        self::assertSame(
            'Value Gas Flex · Μηδενική εγγύηση',
            ProtergiaGasSheets::remarks(
                'protergia_gas_flex',
                'Protergia Gas Flex — Κυμαινόμενο',
                " Μηδενική \n εγγύηση "
            )
        );
        self::assertSame('Value Gas Standard', ProtergiaGasSheets::remarks('protergia_epag_gas_standard', 'x', ''));
        // Άγνωστο πρόγραμμα: το όνομα όπως αποθηκεύτηκε στη σύμβαση.
        self::assertSame('Παλιό πρόγραμμα', ProtergiaGasSheets::remarks('kati_allo', ' Παλιό πρόγραμμα ', ''));
    }

    public function testOnlyTheMeterTypesOfTheGuaranteeTableAreAccepted(): void
    {
        self::assertSame(
            ['G2,5', 'G4', 'G6', 'G10', 'G16', 'G25', 'G40', 'G65', 'G100', 'G160'],
            ProtergiaGasSheets::METER_TYPES
        );
        self::assertSame('G4', ProtergiaGasSheets::meterType(' G4 '));
        self::assertSame('', ProtergiaGasSheets::meterType('G5'));
    }

    public function testOnlyACompanyGetsTheLegalRepresentativeCaption(): void
    {
        self::assertSame(
            'ΜΙΧΑΛΗΣ ΚΑΠΕΤΑΝΑΚΗΣ - ΝΟΜΙΜΟΣ ΕΚΠΡΟΣΩΠΟΣ',
            ProtergiaGasSheets::legalRepCaption('company', ' Μιχάλης  Καπετανάκης ')
        );
        self::assertSame('', ProtergiaGasSheets::legalRepCaption('sole_prop', 'Μιχάλης Καπετανάκης'));
        self::assertSame('', ProtergiaGasSheets::legalRepCaption('individual', 'Μιχάλης Καπετανάκης'));
        self::assertSame('', ProtergiaGasSheets::legalRepCaption('company', '  '));
    }

    private static function dir(): string
    {
        return dirname(__DIR__, 4) . '/assets/forms/protergia/';
    }

    /** @return array<string, mixed> */
    private function map(string $tpl): array
    {
        $m = json_decode((string) file_get_contents(self::dir() . $tpl . '.json'), true);
        self::assertIsArray($m, $tpl);

        return $m;
    }
}
