<?php

/**
 * (345) Ημερολογιακοί μήνες «YYYY-MM», χωρίς ρολόι.
 *
 * Ποιος είναι ο τρέχων μήνας το λέει η βάση (StoreRepository::currentMonth());
 * εδώ μόνο αριθμητική πάνω σε αυτόν: προηγούμενος, επόμενος, η πρώτη μέρα,
 * το ελληνικό όνομα. Καθαρή, χωρίς WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Analytics;

final class Month
{
    private const NAMES = [
        1 => 'Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος',
        'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος',
    ];

    private function __construct()
    {
    }

    /** Έγκυρος μήνας «YYYY-MM» (2000–2099); αλλιώς null. */
    public static function parse(string $month): ?string
    {
        if (preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return null;
        }

        return $month;
    }

    public static function previous(string $month): string
    {
        return self::shift($month, -1);
    }

    public static function next(string $month): string
    {
        return self::shift($month, 1);
    }

    /** «2026-10-01»: η πρώτη μέρα, για σύγκριση με ημερομηνίες της βάσης. */
    public static function firstDay(string $month): string
    {
        return $month . '-01';
    }

    /** «Οκτώβριος 2026». */
    public static function label(string $month): string
    {
        [$year, $number] = array_map('intval', explode('-', $month));

        return (self::NAMES[$number] ?? $month) . ' ' . $year;
    }

    /** «Οκτώβριος», χωρίς έτος. */
    public static function name(string $month): string
    {
        return self::NAMES[(int) substr($month, 5, 2)] ?? $month;
    }

    /** «Οκτώβριο», για «από Οκτώβριο», «ίδιοι με τον Οκτώβριο». */
    public static function accusative(string $month): string
    {
        $name = self::name($month);

        return str_ends_with($name, 'ος') ? mb_substr($name, 0, -1, 'UTF-8') : $name;
    }

    /**
     * Ο τρέχων και οι $count - 1 προηγούμενοι, νεότερος πρώτος.
     *
     * @return list<string>
     */
    public static function recent(string $current, int $count): array
    {
        $out = [];

        for ($i = 0, $month = $current; $i < $count; $i++, $month = self::previous($month)) {
            $out[] = $month;
        }

        return $out;
    }

    /** Πόσοι μήνες από το $from ως το $to (αρνητικό αν το $to είναι πριν). */
    public static function distance(string $from, string $to): int
    {
        [$fy, $fm] = array_map('intval', explode('-', $from));
        [$ty, $tm] = array_map('intval', explode('-', $to));

        return ($ty - $fy) * 12 + ($tm - $fm);
    }

    private static function shift(string $month, int $by): string
    {
        [$year, $number] = array_map('intval', explode('-', $month));
        $index           = $year * 12 + ($number - 1) + $by;

        return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
    }
}
