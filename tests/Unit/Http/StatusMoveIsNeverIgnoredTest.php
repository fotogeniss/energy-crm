<?php

/**
 * (403) Το `ContractLifecycle::moveTo()` γυρίζει false όταν ΔΕΝ έκανε τη
 * μετάβαση (λάθος είδος αίτησης, χωρίς υπογραφή, άλλος την άλλαξε στο
 * μεταξύ). Δύο controllers το αγνοούσαν και απαντούσαν «ΟΚ» με τη νέα
 * κατάσταση· η λίστα έδειχνε κατάσταση που η βάση δεν είχε.
 *
 * Κάθε κλήση του στους controllers πρέπει να κρατά το αποτέλεσμα
 * (`$x = ...->moveTo(`) ή να το ελέγχει (`if (...->moveTo(`).
 *
 * @kanonas K4
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

final class StatusMoveIsNeverIgnoredTest extends TestCase
{
    public function testEveryControllerLooksAtTheResultOfMoveTo(): void
    {
        $root      = dirname(__DIR__, 3);
        $offenders = [];
        $calls     = 0;

        foreach (glob($root . '/src/Http/*.php') ?: [] as $path) {
            foreach (file($path) ?: [] as $n => $line) {
                if (! str_contains($line, '->moveTo(')) {
                    continue;
                }

                $calls++;

                if (preg_match('/(=|if\s*\(|return)\s*!?\s*\$this->lifecycle->moveTo\(/', $line) !== 1) {
                    $offenders[] = basename($path) . ':' . ($n + 1);
                }
            }
        }

        self::assertGreaterThan(0, $calls, 'Δεν βρέθηκε καμία κλήση moveTo() -- μετακινήθηκαν οι controllers;');
        self::assertSame(
            [],
            $offenders,
            'Το αποτέλεσμα του moveTo() αγνοείται: η απάντηση θα έλεγε «ΟΚ» για μετάβαση που δεν έγινε.'
        );
    }
}
