<?php

/**
 * (456) Volton: αίτηση + Ειδικοί Όροι του προγράμματος + Προωθητική.
 * (457) + Γενικοί Όροι, νέα αίτηση, νέα μπλε.
 *
 * @kanonas K7
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Forms;

use EnergyCRM\Domain\Forms\FormTemplates;
use EnergyCRM\Domain\Forms\VoltonPaperwork;
use EnergyCRM\Domain\Forms\VoltonPlans;
use PHPUnit\Framework\TestCase;

final class VoltonPaperworkTest extends TestCase
{
    private static function formsDir(): string
    {
        return dirname(__DIR__, 4) . '/assets/forms';
    }

    /** @return array<string, mixed> */
    private static function map(string $key): array
    {
        $map = json_decode((string) file_get_contents(FormTemplates::mapPath(self::formsDir(), $key)), true);
        self::assertIsArray($map, $key);

        return $map;
    }

    public function testStayAndWinGetsItsTermsAndThePromotion(): void
    {
        self::assertSame(
            ['volton_he', 'volton_go', 'volton_eo_stay_win', 'volton_promo_stay_win_g1_g21'],
            VoltonPaperwork::forProgram('volton_stay_win')
        );
        self::assertSame(
            ['volton_he', 'volton_go', 'volton_eo_stay_win_g21', 'volton_promo_stay_win_g1_g21'],
            VoltonPaperwork::forProgram('volton_stay_win_g21')
        );
        self::assertSame(
            ['volton_he', 'volton_go', 'volton_eo_stay_win_g23', 'volton_promo_stay_win_g23'],
            VoltonPaperwork::forProgram('volton_stay_win_g23')
        );
    }

    public function testAProgramWithoutPromotionGetsOnlyItsTerms(): void
    {
        self::assertSame(
            ['volton_he', 'volton_go', 'volton_eo_yellow_simple_g22'],
            VoltonPaperwork::forProgram('volton_yellow_simple_g22')
        );
    }

    /** Παλιά μπλε, πράσινα, άγνωστο: η αίτηση και οι Γενικοί Όροι. */
    public function testWithoutTermsTheApplicationAndTheGeneralTerms(): void
    {
        self::assertSame(['volton_he', 'volton_go'], VoltonPaperwork::forProgram('volton_blue_flat'));
        self::assertSame(['volton_he', 'volton_go'], VoltonPaperwork::forProgram(''));
    }

    /** (457) Τα νέα μπλε: Ειδικοί Όροι, και Προωθητική μόνο στα Fixed Plus. */
    public function testTheNewBluePlansGetTheirTerms(): void
    {
        self::assertSame(
            ['volton_he', 'volton_go', 'volton_eo_blue_fixed_plus_18m', 'volton_promo_blue_fixed_plus_18m'],
            VoltonPaperwork::forProgram('volton_blue_fixed_plus_18m')
        );
        self::assertSame(
            [
                'volton_he', 'volton_go',
                'volton_eo_blue_fixed_plus_18m_g21', 'volton_promo_blue_fixed_plus_18m_g21',
            ],
            VoltonPaperwork::forProgram('volton_blue_fixed_plus_18m_g21')
        );
        self::assertSame(
            ['volton_he', 'volton_go', 'volton_eo_blue_flat_12m'],
            VoltonPaperwork::forProgram('volton_blue_flat_12m')
        );
        self::assertSame(
            ['volton_he', 'volton_go', 'volton_eo_blue_flat_24m_g21'],
            VoltonPaperwork::forProgram('volton_blue_flat_24m_g21')
        );
        self::assertSame(
            ['volton_he', 'volton_go', 'volton_eo_blue_flat_12m_g21'],
            VoltonPaperwork::forProgram('volton_blue_flat_12m_g21')
        );
    }

    /** K1/K3: ό,τι υπογράφηκε πριν κρατά το χαρτί του. */
    public function testSignedBeforeKeepsTheOldPaper(): void
    {
        self::assertSame(['volton_he_v1'], VoltonPaperwork::forProgram('volton_stay_win', '2026-11-20 10:00:00'));
        self::assertSame(
            ['volton_he_v1', 'volton_eo_stay_win', 'volton_promo_stay_win_g1_g21'],
            VoltonPaperwork::forProgram('volton_stay_win', VoltonPaperwork::FROM)
        );
        self::assertSame(
            ['volton_he', 'volton_go', 'volton_eo_stay_win', 'volton_promo_stay_win_g1_g21'],
            VoltonPaperwork::forProgram('volton_stay_win', VoltonPaperwork::EDITION)
        );
    }

    /**
     * (457) Η νέα αίτηση συμπληρώνεται όπως η παλιά. Μόνη διαφορά: η Volton
     * έβαλε το «ΚΙΝΗΤΟ:» αμέσως μετά το «ΤΗΛΕΦΩΝΟ:» του πελάτη, οπότε το
     * σταθερό δεν έχει πια θέση· και ο τόπος γράφεται δίπλα στην υπογραφή.
     */
    public function testTheNewApplicationFillsWhatTheOldOneDid(): void
    {
        $old = array_keys(self::map(VoltonPaperwork::APPLICATION_V1)['fields']);
        $new = array_keys(self::map(VoltonPaperwork::APPLICATION)['fields']);

        self::assertSame(['tilefono_pelati'], array_values(array_diff($old, $new)));
        self::assertSame(['topos_imerominia_aitisis'], array_values(array_diff($new, $old)));
        $pages = FormTemplates::pages(self::formsDir(), VoltonPaperwork::APPLICATION, self::map('volton_he'));
        self::assertCount(2, $pages);
    }

    /** Κάθε πρόγραμμα με όρους υπάρχει στον κατάλογο, αλλιώς δεν θα το διάλεγε κανείς. */
    public function testEveryProgramWithTermsIsInTheCatalogue(): void
    {
        foreach (VoltonPaperwork::programsWithTerms() as $code) {
            self::assertTrue(VoltonPlans::exists($code), $code);
        }
    }

    /** Σελίδες Letter (οι Γενικοί Όροι 5 σελίδες 215,9 × 303), υπογραφή και τόπος/ημερομηνία στην τελευταία σελίδα. */
    public function testEverySheetIsOnDiskWithASignature(): void
    {
        foreach (VoltonPaperwork::templates() as $key) {
            $map   = self::map($key);
            $pages = FormTemplates::pages(self::formsDir(), $key, $map);

            self::assertNotSame([], $pages, $key);

            if ($key === VoltonPaperwork::APPLICATION || $key === VoltonPaperwork::APPLICATION_V1) {
                continue;
            }

            self::assertSame(215.9, $map['page_w'], $key);
            self::assertSame($key === VoltonPaperwork::GENERAL_TERMS ? 303.0 : 279.4, $map['page_h'], $key);
            self::assertCount(1, $map['sigs'], $key);
            self::assertSame(count($pages), $map['sigs'][0]['page'], $key);
            self::assertNotSame([], $map['fields'], $key);
        }

        self::assertCount(5, FormTemplates::pages(self::formsDir(), 'volton_go', self::map('volton_go')));
    }
}
