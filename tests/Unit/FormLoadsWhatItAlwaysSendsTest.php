<?php

/**
 * (420) Ό,τι η φόρμα στέλνει ΠΑΝΤΑ στην αποθήκευση, το φορτώνει πρώτα στην
 * επεξεργασία.
 *
 * Το `collect()` του `ecrm-form.js` στέλνει κάποια πεδία ακόμη κι όταν είναι
 * κενά (σημειώσεις, συναίνεση). Αν το `applyEdit()` δεν τα γεμίσει από την
 * αίτηση, η πρώτη αποθήκευση τα σβήνει. Έτσι χάνονταν οι σημειώσεις σε κάθε
 * επεξεργασία, ως τις 18/11 (βρέθηκε στους ελέγχους στο live).
 *
 * @kanonas K7
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FormLoadsWhatItAlwaysSendsTest extends TestCase
{
    public function testEverySelectorCollectReadsIsFilledByApplyEdit(): void
    {
        $js      = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/ecrm-form.js');
        $collect = self::body($js, 'collect');
        $edit    = self::body($js, 'applyEdit');

        self::assertNotSame('', $collect, 'Δεν βρέθηκε το collect() στο ecrm-form.js.');
        self::assertNotSame('', $edit, 'Δεν βρέθηκε το applyEdit() στο ecrm-form.js.');

        preg_match_all("/q\\('(\\[data-[a-z-]+\\])'\\)/", $collect, $m);
        $missing = array_values(array_filter(
            array_unique($m[1]),
            static fn (string $selector): bool => ! str_contains($edit, $selector)
        ));

        self::assertNotSame([], $m[1], 'Το collect() δεν διαβάζει πια πεδία με q(...): ξαναγράψε τον έλεγχο.');
        self::assertSame(
            [],
            $missing,
            'Το collect() στέλνει πάντα ' . implode(', ', $missing) . ' αλλά το applyEdit() δεν το φορτώνει: '
            . 'η πρώτη αποθήκευση από τη φόρμα θα το σβήσει.'
        );
    }

    /** Το σώμα μιας `function name(` ως την επόμενη συνάρτηση του ίδιου επιπέδου. */
    private static function body(string $js, string $name): string
    {
        $start = strpos($js, 'function ' . $name . '(');

        if ($start === false) {
            return '';
        }

        $next = strpos($js, "\n\t\tfunction ", $start + 10);

        return substr($js, $start, $next === false ? null : $next - $start);
    }
}
