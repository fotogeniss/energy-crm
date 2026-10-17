<?php

/**
 * (319) Protergia ρεύμα: ό,τι ζήτησε ο ιδιοκτήτης να τυπώνεται, σε ΟΛΑ τα
 * φύλλα (τέσσερα οικιακά, γενικό οικιακό, τρία επαγγελματικά, γενικό
 * επαγγελματικό).
 *
 * Τα όρια των κουτιών είναι μετρημένα στα JPG (1241x1754px): σελ.1 «ΦΑΣΕΙΣ
 * ΠΑΡΟΧΗΣ» και «ΜΕΤΡΗΣΗ», σελίδα «Αποδοχή όρων» (Α, Β1, Β2, Ζ, Η, Θ).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\ProtergiaBizCatalogue;
use EnergyCRM\Domain\Forms\ProtergiaBizPlans;
use EnergyCRM\Domain\Forms\ProtergiaPicassoPlans;
use EnergyCRM\Domain\Forms\Remarks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProtergiaPrint319Test extends TestCase
{
    /** template => σελίδα «Αποδοχή όρων» */
    private const HOME = [
        'protergia_oik_lite2' => 3, 'protergia_oik_sure12' => 3, 'protergia_oik_sure18' => 3,
        'protergia_oik_bright' => 3, 'protergia_he' => 4,
    ];

    private const BIZ = [
        'protergia_epag_sure12' => 3, 'protergia_epag_simple2' => 3, 'protergia_epag_seasonal' => 3,
        'protergia_he_biz' => 4,
    ];

    /** @return list<array{string, int, bool}> */
    public static function templates(): array
    {
        $out = [];
        foreach (self::HOME as $t => $p) {
            $out[$t] = [$t, $p, true];
        }
        foreach (self::BIZ as $t => $p) {
            $out[$t] = [$t, $p, false];
        }

        return array_values($out);
    }

    #[DataProvider('templates')]
    public function testEverythingTheOwnerAskedForHasAPlace(string $tpl, int $terms, bool $home): void
    {
        $f = $this->fields($tpl);

        foreach (
            ['isxis_paroxis', 'fasi_monofasiki', 'fasi_trifasiki', 'metrisi_imerisia',
            'metrisi_imerisia_nyxterini', 'metrisi_tilemetroumeni', 'poso_eggiisis'] as $key
        ) {
            self::assertArrayHasKey($key, $f, "{$tpl}: λείπει το {$key}");
        }

        foreach (
            ['apostoli_logariasmou_entypo', 'apostoli_logariasmou_email',
            'enimerosi_oron_taxydromika', 'enimerosi_oron_email', 'enimerosi_oron_logariasmos',
            'enimerosi_oron_systimeni', 'enimerosi_oron_tilefonika', 'enimerosi_oron_eidopoiisi',
            'synainesi_omilou_nai', 'synainesi_omilou_oxi', 'synainesi_erevnas_nai',
            'synainesi_erevnas_oxi', 'paratiriseis'] as $key
        ) {
            self::assertArrayHasKey($key, $f, "{$tpl}: λείπει το {$key}");
            self::assertSame($terms, $this->first($f[$key])['page'], "{$tpl}: το {$key} όχι στη σελίδα όρων");
        }

        // Η ΚΟΤ μόνο στις Παρατηρήσεις, ποτέ δίπλα στην «Κατηγορία Παροχής».
        self::assertArrayNotHasKey('eidiki_katigoria', $f, "{$tpl}: η ΚΟΤ τυπώνεται ακόμα στην Κατηγορία Παροχής");

        // Ο όρος Θ (υπαναχώρηση) υπάρχει μόνο στα οικιακά φύλλα.
        if ($home) {
            self::assertArrayHasKey('protergia_oros_th', $f);
        } else {
            self::assertArrayNotHasKey('protergia_oros_th', $f);
        }

        $r = $this->first($f['paratiriseis']);
        self::assertGreaterThan(0, $r['lh'] ?? 0, 'Οι Παρατηρήσεις θέλουν δύο γραμμές.');
    }

    /** Το Χ (10pt στα στρογγυλά, 9pt στα τετράγωνα) μέσα στο κουτί του. */
    #[DataProvider('templates')]
    public function testTheMarksLandInsideTheirBoxes(string $tpl, int $terms, bool $home): void
    {
        $f = $this->fields($tpl);
        $biz = ! $home;

        // σελ.1: [x0, x1, y0, y1] του στρογγυλού κουτιού (μετρημένα)
        $phaseY = $biz ? [248.8, 251.7] : [247.9, 251.3];
        $pill = [
            'fasi_monofasiki' => [125.7, 132.1, ...$phaseY],
            'fasi_trifasiki'  => [155.2, 161.6, ...$phaseY],
        ];
        foreach ($pill as $key => [$x0, $x1, $y0, $y1]) {
            $p = $this->first($f[$key]);
            self::assertGreaterThanOrEqual($x0, $p['x'], "{$tpl}:{$key} αριστερά από το κουτί");
            self::assertLessThanOrEqual($x1, $p['x'] + 2.4, "{$tpl}:{$key} δεξιά από το κουτί");
            // βασική γραμμή = y + 2,5 · το Χ (ύψος ~2,6) πρέπει να ακουμπά το κουτί
            self::assertGreaterThan($y0, $p['y'] + 2.5, "{$tpl}:{$key} πάνω από το κουτί");
            self::assertLessThanOrEqual($y1 + 0.3, $p['y'] + 2.5, "{$tpl}:{$key} κάτω από το κουτί");
        }

        // σελίδα όρων: τετράγωνα 3,2 mm, πάνω αριστερή γωνία
        $sq = [
            'apostoli_logariasmou_entypo' => [77.5, 125.7], 'apostoli_logariasmou_email' => [93.7, 125.7],
            'enimerosi_oron_taxydromika' => [36.9, 137.2], 'enimerosi_oron_email' => [53.0, 137.2],
            'enimerosi_oron_logariasmos' => [94.1, 137.2], 'enimerosi_oron_systimeni' => [138.3, 137.2],
            'enimerosi_oron_tilefonika' => [24.0, 145.0], 'enimerosi_oron_eidopoiisi' => [122.9, 145.0],
            'synainesi_omilou_nai' => [50.9, 197.8], 'synainesi_omilou_oxi' => [61.6, 197.8],
            'synainesi_erevnas_nai' => [67.0, 208.0], 'synainesi_erevnas_oxi' => [77.0, 208.0],
        ];
        if ($home) {
            $sq['protergia_oros_th'] = [54.3, 215.2];
        }
        foreach ($sq as $key => [$bx, $by]) {
            $p = $this->first($f[$key]);
            // ανοχή 0,5 mm για τις μικρές διαφορές μεταξύ των JPG
            self::assertGreaterThanOrEqual($bx - 0.5, $p['x'], "{$tpl}:{$key}");
            self::assertLessThanOrEqual($bx + 3.2 + 0.5, $p['x'] + 2.2, "{$tpl}:{$key}");
            self::assertGreaterThanOrEqual($by - 0.5, $p['y'] + 2.5 - 2.3, "{$tpl}:{$key}");
            self::assertLessThanOrEqual($by + 3.2 + 0.5, $p['y'] + 2.5, "{$tpl}:{$key}");
        }
    }

    public function testRemarksPutEachThingOnItsOwnLine(): void
    {
        self::assertSame(
            "Ειδική κατηγορία: ΚΟΤ Α\nΠροωθητική ενέργεια: Μηδενική εγγύηση",
            Remarks::lines('ΚΟΤ Α', 'Μηδενική εγγύηση')
        );
        self::assertSame('Προωθητική ενέργεια: Χ', Remarks::lines('  ', " Χ \n"));
        self::assertSame('', Remarks::lines('', ''));
    }

    public function testSplitKeepsOldSingleLinePlacesOnOneLine(): void
    {
        self::assertSame(['α', 'β'], Remarks::split("α\nβ", true));
        self::assertSame(['α · β'], Remarks::split("α\r\nβ", false));
        self::assertSame([], Remarks::split("\n", true));
    }

    public function testTheBusinessCatalogueHasNoDuplicatesAndFitsTheColumn(): void
    {
        $existing = array_merge(ProtergiaBizPlans::codes(), array_keys(ProtergiaPicassoPlans::all()));

        self::assertCount(15, ProtergiaBizCatalogue::all());
        $power = array_filter(array_keys(ProtergiaBizCatalogue::all()), [ProtergiaBizCatalogue::class, 'isPower']);
        self::assertCount(13, $power);

        foreach (ProtergiaBizCatalogue::all() as $code => $plan) {
            self::assertLessThanOrEqual(32, strlen($code), "{$code}: δεν χωράει στο programs.code");
            self::assertNotContains($code, $existing, "{$code}: υπάρχει ήδη");
            self::assertStringStartsWith('Protergia Επαγγελματικό ', $plan['label']);
            self::assertContains($plan['priceType'], ['', 'fixed', 'variable', 'dynamic', 'special']);
            self::assertContains($plan['energy'], ['power', 'gas']);
            self::assertTrue(ProtergiaBizCatalogue::exists($code));
        }

        self::assertFalse(ProtergiaBizCatalogue::exists(ProtergiaBizPlans::SURE_12));
        // (319) Απόφαση ιδιοκτήτη: το Γ21 Value Sure 12 3.0 αποσύρεται, τα άλλα δύο μένουν.
        self::assertTrue(ProtergiaBizCatalogue::isRetired(ProtergiaBizPlans::SURE_12));
        self::assertFalse(ProtergiaBizCatalogue::isRetired(ProtergiaBizPlans::SIMPLE_2));
        self::assertFalse(ProtergiaBizCatalogue::isPower('protergia_epag_gas_flex'));
        self::assertFileExists(
            dirname(__DIR__, 4) . '/assets/forms/protergia/' . ProtergiaBizCatalogue::TEMPLATE . '.json'
        );
    }

    /** @return array<string, mixed> */
    private function fields(string $tpl): array
    {
        $path = dirname(__DIR__, 4) . '/assets/forms/protergia/' . $tpl . '.json';
        $map  = json_decode((string) file_get_contents($path), true);

        return $map['fields'];
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $v
     * @return array<string, mixed>
     */
    private function first(array $v): array
    {
        return isset($v[0]) ? $v[0] : $v;
    }
}
