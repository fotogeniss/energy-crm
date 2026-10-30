<?php

/**
 * (333) «Δώσε του και αέριο / ρεύμα»: ποιος πελάτης είναι ευκαιρία.
 *
 * Πελάτης με ΕΝΕΡΓΗ σύμβαση μίας ενέργειας και καμία ζωντανή σύμβαση της
 * άλλης. Ζωντανή = οτιδήποτε εκτός από ακυρωμένη: μια αίτηση αερίου σε
 * εξέλιξη σημαίνει ότι η πώληση έγινε. Το πρόχειρο είναι εξαίρεση -- ο
 * πελάτης μένει στη λίστα, με «Άνοιγμα πρόχειρου» αντί για «+ Αέριο».
 *
 * «Όχι τώρα»: ο πελάτης φεύγει από τη λίστα για 90 μέρες. Καταγράφεται στο
 * ιστορικό του πελάτη (customer_events), ώστε να φαίνεται ποιος, πότε, γιατί.
 *
 * Mockup: docs/UI-CROSS-SELL.html.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Customer;

final class CrossSell
{
    public const SNOOZE_DAYS = 90;

    /** Η ενέργεια που λείπει => η ενέργεια που ήδη έχει. */
    private const HAS = ['gas' => 'power', 'power' => 'gas'];

    private function __construct()
    {
    }

    /** Ποια ενέργεια πρέπει να έχει ήδη ενεργή, για να του προτείνουμε την `$want`· '' αν άκυρη. */
    public static function has(string $want): string
    {
        return self::HAS[$want] ?? '';
    }

    /** Το πεδίο του ιστορικού πελάτη που κρατά το «Όχι τώρα». */
    public static function snoozeField(string $want): string
    {
        return isset(self::HAS[$want]) ? 'cross_sell_' . $want : '';
    }

    /** Η πρώτη μέρα που ο πελάτης ξαναμπαίνει στη λίστα μετά από «Όχι τώρα» στις `$day`. */
    public static function snoozedSince(string $today): string
    {
        $time = strtotime($today . ' 00:00:00 UTC');

        return gmdate('Y-m-d', ($time === false ? 0 : $time) - self::SNOOZE_DAYS * 86400);
    }

    /** Το κείμενο που μένει στο ιστορικό του πελάτη. */
    public static function snoozeNote(string $note): string
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));

        return 'Όχι τώρα' . ($note !== '' ? ' — ' . mb_substr($note, 0, 200) : '');
    }
}
