<?php

/**
 * (328) Ό,τι διάβασε το AI από έναν λογαριασμό, σε αριθμούς που αντέχουν.
 *
 * Το μοντέλο επιστρέφει JSON, αλλά οι αριθμοί ενός ελληνικού λογαριασμού
 * είναι «0,1490» και «1.325», και η τιμή γράφεται άλλοτε σε €/kWh κι άλλοτε
 * σε €/MWh. Εδώ γίνονται όλα float με μία ερμηνεία, ώστε η σύγκριση να μην
 * εξαρτάται από το πώς το έγραψε το μοντέλο αυτή τη φορά.
 *
 * Ό,τι δεν βγάζει νόημα γίνεται null και ο πωλητής το συμπληρώνει -- ένα
 * λάθος νούμερο σε προσφορά είναι χειρότερο από ένα κενό πεδίο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Pricing;

final class BillReading
{
    /** Τα κλειδιά που ζητά το prompt του λογαριασμού. */
    public const KEYS = [
        'energy_type', 'provider_name', 'category', 'period_from', 'period_to',
        'period_days', 'kwh', 'price_kwh', 'fixed_month',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{
     *   energy_type: string|null, provider_name: string|null, category: string|null,
     *   period_days: int|null, kwh: float|null, price_kwh: float|null, fixed_month: float|null
     * }
     */
    public static function from(array $raw): array
    {
        $energy   = self::oneOf($raw['energy_type'] ?? null, ['power', 'gas']);
        $category = self::oneOf($raw['category'] ?? null, ['home', 'business']);

        $days = self::number($raw['period_days'] ?? null);

        if ($days === null) {
            $days = self::daysBetween($raw['period_from'] ?? null, $raw['period_to'] ?? null);
        }

        // Ένας λογαριασμός καλύπτει από λίγες μέρες ως έναν χρόνο. Έξω από
        // αυτό είναι λάθος ανάγνωση, όχι λογαριασμός.
        $days = $days !== null && $days >= 1 && $days <= 400 ? (int) round($days) : null;

        $kwh = self::number($raw['kwh'] ?? null);
        $kwh = $kwh !== null && $kwh > 0 ? $kwh : null;

        $price = self::number($raw['price_kwh'] ?? null);

        if ($price !== null && $price > 2) {
            // €/MWh: καμία τιμή προμήθειας δεν είναι 2 €/kWh.
            $price /= 1000;
        }

        $price = $price !== null && $price > 0 && $price < 2 ? round($price, 5) : null;

        $fixed = self::number($raw['fixed_month'] ?? null);
        $fixed = $fixed !== null && $fixed >= 0 && $fixed < 1000 ? round($fixed, 2) : null;

        $provider = is_string($raw['provider_name'] ?? null) ? trim((string) $raw['provider_name']) : '';

        return [
            'energy_type'   => $energy,
            'provider_name' => $provider !== '' && strtolower($provider) !== 'null'
                ? mb_substr($provider, 0, 80)
                : null,
            'category'      => $category,
            'period_days'   => $days,
            'kwh'           => $kwh,
            'price_kwh'     => $price,
            'fixed_month'   => $fixed,
        ];
    }

    /**
     * «1.325,50» → 1325.5, «0,149» → 0.149, «640» → 640.
     */
    public static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $text = preg_replace('/[^\d,.\-]/u', '', $value) ?? '';

        if ($text === '' || $text === '-') {
            return null;
        }

        $comma = strrpos($text, ',');
        $dot   = strrpos($text, '.');

        if ($comma !== false && ($dot === false || $comma > $dot)) {
            // Ελληνική μορφή: η τελευταία κόμμα είναι η υποδιαστολή.
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        } elseif ($dot !== false && $comma === false && preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $text)) {
            // «1.325» χωρίς δεκαδικά: διαχωριστικό χιλιάδων.
            $text = str_replace('.', '', $text);
        } else {
            $text = str_replace(',', '', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(mixed $value, array $allowed): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, $allowed, true) ? $value : null;
    }

    private static function daysBetween(mixed $from, mixed $to): ?float
    {
        if (! is_string($from) || ! is_string($to)) {
            return null;
        }

        $a = strtotime($from . ' 00:00:00 UTC');
        $b = strtotime($to . ' 00:00:00 UTC');

        if ($a === false || $b === false || $b <= $a) {
            return null;
        }

        return ($b - $a) / 86400;
    }
}
