<?php

/**
 * Πού βρίσκονται τα αρχεία ενός εντύπου παρόχου.
 *
 * Ως το (284) όλα ζούσαν χύμα στο `assets/forms/` -- 119 αρχεία, επτά
 * πάροχοι, και για να βρεις τα έντυπα της Volton έπρεπε να τα ξεχωρίσεις με
 * το μάτι ανάμεσα σε 60 της Protergia. Τώρα κάθε πάροχος έχει τον φάκελό του:
 *
 *     assets/forms/protergia/protergia_oik_sure12.json
 *     assets/forms/protergia/protergia_oik_sure12-1.jpg … -6.jpg
 *     assets/forms/orizon/orizon_mobile.json
 *
 * ## Γιατί ο φάκελος βγαίνει από το κλειδί και δεν αποθηκεύεται κάπου
 *
 * Κάθε κλειδί εντύπου ξεκινά ήδη με τον πάροχο (`protergia_`, `orizon_`,
 * `nrg_` …). Ενας πίνακας «κλειδί → φάκελος» θα ήταν δεύτερη πηγή για κάτι
 * που το όνομα ήδη λέει, και ένα νέο έντυπο που ξεχάστηκε από τον πίνακα θα
 * «έλειπε» στην εκτύπωση ενώ το αρχείο κάθεται στον δίσκο. Ο κανόνας είναι
 * ένας: **ό,τι είναι πριν από την πρώτη κάτω παύλα**.
 *
 * Καθαρή κλάση, χωρίς WordPress: τη χρησιμοποιούν και το legacy
 * `ECRM_FormFill` και τα unit tests και τα εργαλεία.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class FormTemplates
{
    private function __construct()
    {
    }

    /**
     * Ο φάκελος του παρόχου για ένα κλειδί εντύπου, ή '' για άκυρο κλειδί.
     *
     * Μόνο πεζά λατινικά και ψηφία: το αποτέλεσμα γίνεται κομμάτι διαδρομής,
     * οπότε ένα κλειδί με `..` ή `/` δεν πρέπει ποτέ να βγάλει φάκελο.
     */
    public static function provider(string $key): string
    {
        return preg_match('/^([a-z0-9]+)_[a-z0-9_]+$/', $key, $m) === 1 ? $m[1] : '';
    }

    /**
     * Ο φάκελος ενός εντύπου, με κάθετο στο τέλος.
     *
     * @param string $formsDir η βάση (`…/assets/forms`), με ή χωρίς κάθετο
     */
    public static function dir(string $formsDir, string $key): string
    {
        return rtrim($formsDir, '/\\') . '/' . self::provider($key) . '/';
    }

    /** Ο χάρτης συντεταγμένων (`<key>.json`). */
    public static function mapPath(string $formsDir, string $key): string
    {
        return self::dir($formsDir, $key) . $key . '.json';
    }

    /** Το υπόβαθρο μιας σελίδας (`<key>-<n>.jpg`), από 1. */
    public static function pagePath(string $formsDir, string $key, int $page): string
    {
        return self::dir($formsDir, $key) . $key . '-' . $page . '.jpg';
    }

    /**
     * Κάθε κλειδί εντύπου που υπάρχει στον δίσκο, ταξινομημένο.
     *
     * Μόνο όσα κάθονται στον φάκελο του ΣΩΣΤΟΥ παρόχου: ένα
     * `volton_he.json` ξεχασμένο μέσα στο `protergia/` δεν θα το έβρισκε
     * ποτέ η εκτύπωση, άρα δεν πρέπει να το βλέπει ούτε η λίστα.
     *
     * @return list<string>
     */
    public static function keys(string $formsDir): array
    {
        $keys = [];

        foreach (glob(rtrim($formsDir, '/\\') . '/*/*.json') ?: [] as $path) {
            $key = basename($path, '.json');

            if (self::provider($key) === basename(dirname($path))) {
                $keys[] = $key;
            }
        }

        sort($keys);

        return $keys;
    }
}
