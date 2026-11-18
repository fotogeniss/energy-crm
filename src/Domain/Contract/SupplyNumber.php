<?php

/**
 * (417) Ένας κανόνας για το «ίδιος αριθμός παροχής».
 *
 * Ο αριθμός αποθηκεύεται όπως τον πληκτρολόγησε ο πωλητής («1 12345678-01 8»),
 * ενώ το Excel του παρόχου τον γράφει συνήθως κολλητά («112345678018»). Η
 * σύγκριση γινόταν κατά γράμμα, οπότε η εισαγωγή καταστάσεων έβγαζε «δεν
 * βρέθηκε» και ο έλεγχος διπλοτύπων δεν έβλεπε τίποτα (έλεγχος 18/11,
 * εύρημα 6). Τώρα και οι δύο πλευρές περνούν από εδώ: κεφαλαία, χωρίς κενά,
 * παύλες και κάθετους. Γράμματα μένουν (ΗΚΑΣΠ αερίου). Η τελεία ΜΕΝΕΙ: ένα
 * κελί Excel «112345678018.0» δεν πρέπει να γίνει «1123456780180».
 *
 * Laravel-ready: καθαρή κλάση· το SQL κομμάτι είναι η ίδια πράξη στη βάση.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class SupplyNumber
{
    /** Ό,τι αφαιρείται: κενά, παύλες, κάθετοι. */
    private const NOISE = [' ', '-', '/'];

    private function __construct()
    {
    }

    public static function key(string $typed): string
    {
        $text = (string) preg_replace('/\s+/u', '', $typed);

        return strtoupper(str_replace(self::NOISE, '', $text));
    }

    /**
     * Η ίδια πράξη πάνω σε στήλη, για το WHERE (`… = %s` με το `key()`).
     * Κενά τύπου tab/αλλαγή γραμμής δεν μπαίνουν σε αριθμό παροχής από τη φόρμα.
     */
    public static function sql(string $column): string
    {
        $expr = 'UPPER(' . $column . ')';

        foreach (self::NOISE as $char) {
            $expr = "REPLACE({$expr}, '{$char}', '')";
        }

        return $expr;
    }
}
