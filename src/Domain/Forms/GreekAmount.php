<?php

/**
 * (424) Ποσό σε ευρώ, ολογράφως, όπως το ζητά η «Αίτηση/Σύμβαση» της ΔΕΗ
 * («…ευρώ (ολογράφως) (… €)» στο άρθρο της εγγύησης).
 *
 * Ο συνεργάτης γράφει την εγγύηση ελεύθερα («120», «120,50», «120 €»). Ό,τι
 * δεν διαβάζεται ως ποσό δίνει κενό: καλύτερα κενό κουτί που θα το γράψει με
 * το χέρι παρά λάθος ποσό στο χαρτί (K7).
 *
 * Χωρίς WordPress, tested.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class GreekAmount
{
    private const ONES = [
        '', 'ένα', 'δύο', 'τρία', 'τέσσερα', 'πέντε', 'έξι', 'επτά', 'οκτώ', 'εννέα',
        'δέκα', 'έντεκα', 'δώδεκα', 'δεκατρία', 'δεκατέσσερα', 'δεκαπέντε', 'δεκαέξι',
        'δεκαεπτά', 'δεκαοκτώ', 'δεκαεννέα',
    ];

    private const TENS = [
        '', '', 'είκοσι', 'τριάντα', 'σαράντα', 'πενήντα', 'εξήντα', 'εβδομήντα', 'ογδόντα', 'ενενήντα',
    ];

    private const HUNDREDS = [
        '', 'εκατό', 'διακόσια', 'τριακόσια', 'τετρακόσια', 'πεντακόσια', 'εξακόσια', 'επτακόσια',
        'οκτακόσια', 'εννιακόσια',
    ];

    /** Ως εδώ γράφεται ολογράφως· πάνω από αυτό το ποσό δεν είναι εγγύηση παροχής. */
    private const MAX = 999999;

    private function __construct()
    {
    }

    /**
     * Το ποσό σε λεπτά, ή null αν δεν διαβάζεται.
     *
     * «1.200,50», «1200.50», «120», «120 €» → λεπτά. Ένα μόνο διαχωριστικό με
     * δύο ή λιγότερα ψηφία μετά του είναι δεκαδικό· αλλιώς χιλιάδες.
     */
    public static function cents(string $typed): ?int
    {
        $s = (string) preg_replace('/[^0-9.,]/', '', $typed);

        if ($s === '' || preg_match('/\d/', $s) !== 1) {
            return null;
        }

        $decimal = '';
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $s, $m) === 1) {
            $s       = $m[1];
            $decimal = $m[2];
        }

        $whole = (string) preg_replace('/[.,]/', '', $s);
        $whole = $whole === '' ? '0' : $whole;

        if (strlen($whole) > 6) {
            return null;
        }

        return (int) $whole * 100 + (int) str_pad($decimal, 2, '0');
    }

    /** «120,00» -- ο αριθμός όπως γράφεται στην παρένθεση του εντύπου. */
    public static function figure(string $typed): string
    {
        $cents = self::cents($typed);

        return $cents === null ? '' : number_format($cents / 100, 2, ',', '.');
    }

    /** Τα ευρώ μόνο («120»), για το έντυπο που τα ζητά χωριστά από τα λεπτά. */
    public static function euros(string $typed): string
    {
        $cents = self::cents($typed);

        return $cents === null ? '' : (string) intdiv($cents, 100);
    }

    /** Τα λεπτά μόνο («00», «50»). */
    public static function centsPart(string $typed): string
    {
        $cents = self::cents($typed);

        return $cents === null ? '' : str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * «Εκατόν είκοσι ευρώ», «Εκατόν είκοσι ευρώ και πενήντα λεπτά», «Μηδέν ευρώ».
     * Με `$currency = false`: «Εκατόν είκοσι» (το «ευρώ» το γράφει το έντυπο).
     */
    public static function words(string $typed, bool $currency = true): string
    {
        $cents = self::cents($typed);

        if ($cents === null || intdiv($cents, 100) > self::MAX) {
            return '';
        }

        $euros = intdiv($cents, 100);
        $rest  = $cents % 100;

        $text = $euros === 0 ? 'μηδέν' : self::number($euros);

        // Χωρίς «ευρώ» όταν το έντυπο το έχει ήδη τυπωμένο μετά το κενό
        // («…ευρώ (ολογράφως)»). Με λεπτά, το ποσό γράφεται ολόκληρο.
        if ($currency || $rest > 0) {
            $text .= ' ευρώ';
        }

        if ($rest > 0) {
            $text .= ' και ' . self::number($rest) . ($rest === 1 ? ' λεπτό' : ' λεπτά');
        }

        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }

    private static function number(int $n): string
    {
        if ($n >= 1000) {
            $thousands = intdiv($n, 1000);
            $head      = $thousands === 1 ? 'χίλια' : self::belowThousand($thousands, true) . ' χιλιάδες';
            $tail      = $n % 1000;

            return $tail === 0 ? $head : $head . ' ' . self::number($tail);
        }

        return self::belowThousand($n);
    }

    private static function belowThousand(int $n, bool $feminine = false): string
    {
        $parts    = [];
        $hundreds = intdiv($n, 100);
        $rest     = $n % 100;

        if ($hundreds > 0) {
            // «εκατόν είκοσι», αλλά σκέτο «εκατό».
            $word = $hundreds === 1 && $rest > 0 ? 'εκατόν' : self::HUNDREDS[$hundreds];

            if ($feminine && $hundreds > 1) {
                $word = (string) preg_replace('/α$/u', 'ες', $word);
            }

            $parts[] = $word;
        }

        if ($rest > 0) {
            if ($rest < 20) {
                $parts[] = self::one($rest, $feminine);
            } else {
                $parts[] = self::TENS[intdiv($rest, 10)];

                if ($rest % 10 > 0) {
                    $parts[] = self::one($rest % 10, $feminine);
                }
            }
        }

        return implode(' ', $parts);
    }

    /** «τρεις χιλιάδες», «τέσσερις χιλιάδες»: θηλυκό μπροστά από τις χιλιάδες. */
    private static function one(int $n, bool $feminine): string
    {
        if ($feminine && $n === 3) {
            return 'τρεις';
        }

        if ($feminine && $n === 4) {
            return 'τέσσερις';
        }

        if ($feminine && $n === 13) {
            return 'δεκατρείς';
        }

        if ($feminine && $n === 14) {
            return 'δεκατέσσερις';
        }

        return self::ONES[$n];
    }
}
