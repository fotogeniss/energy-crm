<?php

/**
 * (443) Μετά από επιτυχημένη αποθήκευση, τα γεμάτα πεδία της φόρμας μετρούν
 * ως αποθηκευμένα (`data-loaded`), όπως όταν ανοίγει υπάρχουσα αίτηση.
 *
 * Το `collect()` στέλνει κενό («σβήσ' το») μόνο για πεδίο με `data-loaded`.
 * Ως τώρα το σημάδι έμπαινε μόνο στο `applyEdit()`. Σε νέα αίτηση που
 * αποθηκεύτηκε μία φορά, ένα πεδίο που ο συνεργάτης άδειαζε μετά δεν
 * στελνόταν, και το έντυπο τύπωνε την παλιά τιμή.
 *
 * @kanonas K7
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FormMarksSavedFieldsLoadedTest extends TestCase
{
    public function testEverySuccessfulSaveMarksTheFieldsLoaded(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/ecrm-form.js');

        self::assertStringContainsString("setAttribute('data-loaded'", self::body($js, 'markLoaded'));

        foreach (['doSave', 'ensureSaved'] as $name) {
            $body = self::body($js, $name);
            self::assertNotSame('', $body, $name . '() δεν βρέθηκε στο ecrm-form.js.');
            self::assertStringContainsString(
                'markLoaded()',
                $body,
                $name . '(): μετά την επιτυχία τα πεδία πρέπει να πάρουν data-loaded.'
            );
        }
    }

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
