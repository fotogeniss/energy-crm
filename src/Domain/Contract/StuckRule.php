<?php

/**
 * (338) Πότε μια αίτηση «κόλλησε»: ο κανόνας της κάρτας «Γιατί κάθεται», σε
 * ένα σημείο για την κάρτα και για τη λίστα «Κολλημένες αιτήσεις».
 *
 * - Με συνηθισμένο χρόνο (διάμεσος του παρόχου για την κατάσταση, δείγμα ≥ 10):
 *   κολλημένη από το διπλάσιο. Το +1 κρατά σιωπή όταν ο συνήθης χρόνος είναι
 *   μηδέν μέρες -- αλλιώς κάθε αυθημερόν κατάσταση θα χτυπούσε από την πρώτη
 *   μέρα.
 * - Χωρίς συνηθισμένο χρόνο: η κάρτα σιωπά (`$limit` null). Η λίστα πέφτει
 *   στο σταθερό όριο ημερών του digest, και το λέει (`compared` = false).
 *
 * Καθαρή λογική: μέρες μέσα, απόφαση έξω.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class StuckRule
{
    private function __construct()
    {
    }

    /**
     * @param array{days: int, sample: int}|null $typical
     * @return array{days: int, typical: int|null, sample: int, compared: bool, limit: int}|null
     */
    public static function judge(int $days, ?array $typical, ?int $limit): ?array
    {
        if ($typical !== null) {
            $threshold = max(2 * $typical['days'], $typical['days'] + 1);

            return $days >= $threshold
                ? [
                    'days'     => $days,
                    'typical'  => $typical['days'],
                    'sample'   => $typical['sample'],
                    'compared' => true,
                    'limit'    => $threshold,
                ]
                : null;
        }

        if ($limit === null || $limit <= 0 || $days < $limit) {
            return null;
        }

        return ['days' => $days, 'typical' => null, 'sample' => 0, 'compared' => false, 'limit' => $limit];
    }

    /**
     * Οι κολλημένες από μια λίστα ανοιχτών αιτήσεων, πιο παλιά πρώτα.
     *
     * Ο συνήθης χρόνος έρχεται απ' έξω (μετριέται στη βάση) και ζητείται μία
     * φορά ανά (κατάσταση, πάροχο): πρώτα του παρόχου, μετά όλων μαζί.
     *
     * @param list<array<string, mixed>> $rows Με `status`, `provider_id`, `dwell` (μέρες ή null).
     * @param callable(string, int|null): (array{days: int, sample: int}|null) $typical
     * @return list<array<string, mixed>> Οι ίδιες γραμμές με `stuck` (judge()).
     */
    public static function filter(array $rows, callable $typical, int $limit): array
    {
        $memo = [];
        $out  = [];

        $lookup = static function (string $status, ?int $provider) use (&$memo, $typical): ?array {
            $key = $status . '|' . (string) $provider;

            if (! array_key_exists($key, $memo)) {
                $memo[$key] = $typical($status, $provider);
            }

            return $memo[$key];
        };

        foreach ($rows as $row) {
            if (! is_numeric($row['dwell'] ?? null)) {
                continue;
            }

            $status   = (string) ($row['status'] ?? '');
            $provider = (int) ($row['provider_id'] ?? 0);
            $known    = $provider > 0 ? $lookup($status, $provider) : null;
            $known  ??= $lookup($status, null);
            $verdict  = self::judge((int) $row['dwell'], $known, $limit);

            if ($verdict !== null) {
                $row['stuck'] = $verdict;
                $out[]        = $row;
            }
        }

        usort($out, static fn (array $a, array $b): int => $b['stuck']['days'] <=> $a['stuck']['days']);

        return $out;
    }
}
