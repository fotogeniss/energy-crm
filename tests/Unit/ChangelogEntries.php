<?php

/**
 * (419) Οι εγγραφές του CHANGELOG, και στις δύο μορφές τίτλου.
 *
 * Παλιά: `## 2026-08-27 (159)`. Από τον Σεπτέμβριο: `### (413) Τίτλος`. Ο έλεγχος
 * «Laravel-ready;» διάβαζε μόνο την παλιά μορφή, οπότε από την αλλαγή και μετά
 * δεν έλεγχε καμία εγγραφή (βρέθηκε 18/11: 66 εγγραφές, (286)–(325), χωρίς τη
 * γραμμή, και κανείς δεν το έμαθε). Ένας αναλυτής για όλους τους ελέγχους.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

final class ChangelogEntries
{
    private const HEADING = '/^(?:## \d{4}-\d{2}-\d{2} \((\d+[a-z]?)\)|### \((\d+[a-z]?)\)[^\n]*)\s*$/mu';

    /** @return array<string|int, string> ετικέτα («413», «406b») => σώμα (αριθμητικές γίνονται int) */
    public static function all(): array
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/CHANGELOG.md');

        if (preg_match_all(self::HEADING, $body, $m, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $out   = [];
        $count = count($m[0]);

        for ($i = 0; $i < $count; $i++) {
            $label = $m[1][$i][0] !== '' && $m[1][$i][1] !== -1 ? (string) $m[1][$i][0] : (string) $m[2][$i][0];
            $start = (int) $m[0][$i][1];
            $end   = $i + 1 < $count ? (int) $m[0][$i + 1][1] : strlen($body);

            $out[$label] ??= substr($body, $start, $end - $start);
        }

        return $out;
    }

    public static function number(string|int $label): int
    {
        return (int) $label;
    }

    public static function isNewFormat(string $body): bool
    {
        return str_starts_with($body, '### ');
    }
}
