<?php

/**
 * (419) Κάθε κανόνας του `docs/KANONES.md` έχει τουλάχιστον ένα test, και κάθε
 * `@kanonas` σε test δείχνει σε κανόνα που υπάρχει.
 *
 * Ο ιδιοκτήτης (18/11): «γράψ' τους να ακολουθούνται ρητά». Ένα έγγραφο που
 * περιγράφει κανόνα δεν τον επιβάλλει· μια κόκκινη σουίτα τον επιβάλλει. Κανόνας
 * χωρίς κώδικα να ελεγχθεί (π.χ. «live μόνο από τον ιδιοκτήτη») γράφει στον τίτλο
 * του «χωρίς test» και εξαιρείται ρητά, όχι σιωπηλά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class EveryRuleHasATestTest extends TestCase
{
    /**
     * Οι κανόνες: id => αν χρειάζεται test.
     *
     * @return array<string, bool>
     */
    public static function rules(): array
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/KANONES.md');

        preg_match_all('/^### (K\d+) — (.*)$/mu', $doc, $m, PREG_OFFSET_CAPTURE);

        $out   = [];
        $count = count($m[0]);

        for ($i = 0; $i < $count; $i++) {
            $start = (int) $m[0][$i][1];
            $end   = $i + 1 < $count ? (int) $m[0][$i + 1][1] : strlen($doc);
            $body  = substr($doc, $start, $end - $start);

            $out[(string) $m[1][$i][0]] = ! str_contains($body, 'χωρίς test');
        }

        return $out;
    }

    /** @return array<string, list<string>> κανόνας => αρχεία test */
    private static function tagged(): array
    {
        $root = dirname(__DIR__);
        $out  = [];
        $it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === basename(__FILE__)) {
                continue;
            }

            preg_match_all('/@kanonas (K\d+)/', (string) file_get_contents($file->getPathname()), $m);

            foreach ($m[1] as $rule) {
                $out[$rule][] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        return $out;
    }

    public function testTheDocumentHasRules(): void
    {
        self::assertGreaterThanOrEqual(
            10,
            count(self::rules()),
            'Ο αναλυτής δεν βρήκε τους κανόνες στο docs/KANONES.md.'
        );
    }

    public function testEveryRuleIsGuardedByATest(): void
    {
        $tagged  = self::tagged();
        $missing = [];

        foreach (self::rules() as $rule => $needsTest) {
            if ($needsTest && empty($tagged[$rule])) {
                $missing[] = $rule;
            }
        }

        self::assertSame(
            [],
            $missing,
            'Κανόνες χωρίς test: ' . implode(', ', $missing) . ".\n"
            . 'Βάλε `@kanonas K#` στο docblock του test που τον φυλάει (docs/KANONES.md, διαδικασία 4).'
        );
    }

    public function testEveryTagPointsToARealRule(): void
    {
        $rules   = self::rules();
        $unknown = array_values(array_diff(array_keys(self::tagged()), array_keys($rules)));

        self::assertSame(
            [],
            $unknown,
            'Tests με `@kanonas` που δεν υπάρχει στο docs/KANONES.md: ' . implode(', ', $unknown)
        );
    }
}
