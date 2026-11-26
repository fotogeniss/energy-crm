<?php

/**
 * (456) Volton: αίτηση + Ειδικοί Όροι του προγράμματος + Προωθητική.
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
            ['volton_he', 'volton_eo_stay_win', 'volton_promo_stay_win_g1_g21'],
            VoltonPaperwork::forProgram('volton_stay_win')
        );
        self::assertSame(
            ['volton_he', 'volton_eo_stay_win_g21', 'volton_promo_stay_win_g1_g21'],
            VoltonPaperwork::forProgram('volton_stay_win_g21')
        );
        self::assertSame(
            ['volton_he', 'volton_eo_stay_win_g23', 'volton_promo_stay_win_g23'],
            VoltonPaperwork::forProgram('volton_stay_win_g23')
        );
    }

    public function testAProgramWithoutPromotionGetsOnlyItsTerms(): void
    {
        self::assertSame(
            ['volton_he', 'volton_eo_yellow_simple_g22'],
            VoltonPaperwork::forProgram('volton_yellow_simple_g22')
        );
    }

    /** Μπλε, πράσινα, αέριο, άγνωστο: μόνο η αίτηση, όπως πριν. */
    public function testWithoutTermsOnlyTheApplication(): void
    {
        self::assertSame(['volton_he'], VoltonPaperwork::forProgram('volton_blue_flat'));
        self::assertSame(['volton_he'], VoltonPaperwork::forProgram(''));
    }

    /** K1/K3: ό,τι υπογράφηκε πριν κρατά το χαρτί του. */
    public function testSignedBeforeKeepsTheOldPaper(): void
    {
        self::assertSame(['volton_he'], VoltonPaperwork::forProgram('volton_stay_win', '2026-11-20 10:00:00'));
        self::assertCount(3, VoltonPaperwork::forProgram('volton_stay_win', VoltonPaperwork::FROM));
    }

    /** Κάθε πρόγραμμα με όρους υπάρχει στον κατάλογο, αλλιώς δεν θα το διάλεγε κανείς. */
    public function testEveryProgramWithTermsIsInTheCatalogue(): void
    {
        foreach (VoltonPaperwork::programsWithTerms() as $code) {
            self::assertTrue(VoltonPlans::exists($code), $code);
        }
    }

    /** Σελίδες Letter, υπογραφή και τόπος/ημερομηνία στην τελευταία σελίδα. */
    public function testEverySheetIsOnDiskWithASignature(): void
    {
        foreach (VoltonPaperwork::templates() as $key) {
            $map   = self::map($key);
            $pages = FormTemplates::pages(self::formsDir(), $key, $map);

            self::assertNotSame([], $pages, $key);

            if ($key === VoltonPaperwork::APPLICATION) {
                continue;
            }

            self::assertSame(215.9, $map['page_w'], $key);
            self::assertSame(279.4, $map['page_h'], $key);
            self::assertCount(1, $map['sigs'], $key);
            self::assertSame(count($pages), $map['sigs'][0]['page'], $key);
            self::assertNotSame([], $map['fields'], $key);
        }
    }
}
