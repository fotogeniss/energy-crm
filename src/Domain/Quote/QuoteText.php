<?php

/**
 * (376) Πώς γράφονται τα νούμερα στο PDF προσφοράς: ελληνικοί αριθμοί
 * (190,05 €, 3.660 kWh) αντί για 190.05 €, και η διαφορά με πρόσημο.
 * Τα κεφαλαία: `Domain\Document\GreekCaps`. docs/UI-QUOTE-PDF.html.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Quote;

final class QuoteText
{
    private const TARIFF = [
        'fixed'    => 'Σταθερό',
        'variable' => 'Κυμαινόμενο',
        'special'  => 'Ειδικό',
        'dynamic'  => 'Δυναμικό',
    ];

    private function __construct()
    {
    }

    /** 190,05 · 0,13500 · 3.660 */
    public static function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }

    /** 190,05 € */
    public static function euro(float $value): string
    {
        return self::number($value, 2) . ' €';
    }

    /** Η διαφορά με πρόσημο: −106,14 € / +12,00 € / 0,00 €. */
    public static function signedEuro(float $value): string
    {
        $abs = self::euro(abs($value));

        if (round($value, 2) === 0.0) {
            return $abs;
        }

        return ($value < 0 ? '−' : '+') . $abs;
    }

    /** Το χρώμα του τιμολογίου ως λέξη· '' όταν δεν είναι γνωστό. */
    public static function tariff(string $priceType): string
    {
        return self::TARIFF[$priceType] ?? '';
    }
}
