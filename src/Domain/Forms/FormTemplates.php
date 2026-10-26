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
     * Όλα τα υπόβαθρα ενός εντύπου, με τη σειρά που τυπώνονται.
     *
     * (322) Πρώτα οι δικές του σελίδες (`<key>-1.jpg` …), μετά -- αν ο χάρτης
     * δηλώνει `"tail"` -- οι σελίδες ενός κοινού συνόλου εικόνων
     * (`<tail>-1.jpg` …), αριθμημένες συνέχεια. Έτσι οι Γενικοί Όροι που
     * είναι ίδιοι σε εννέα φύλλα (Picasso 2.0) κάθονται μία φορά στον δίσκο,
     * όχι εννέα. Το `tail` δεν είναι έντυπο (δεν έχει χάρτη) και πρέπει να
     * είναι του ίδιου παρόχου: ποτέ διαδρομή έξω από τον φάκελό του.
     *
     * @param array<string, mixed> $map
     *
     * @return list<string>
     */
    public static function pagePaths(string $formsDir, string $key, array $map): array
    {
        $out = [];

        for ($p = 1; is_file(self::pagePath($formsDir, $key, $p)); $p++) {
            $out[] = self::pagePath($formsDir, $key, $p);
        }

        $tail = self::tail($key, $map);

        for ($p = 1; $tail !== '' && is_file(self::pagePath($formsDir, $tail, $p)); $p++) {
            $out[] = self::pagePath($formsDir, $tail, $p);
        }

        return $out;
    }

    /**
     * Τα υπόβαθρα μαζί με το μέγεθος χαρτιού της κάθε σελίδας, σε mm.
     *
     * (323) Οι αιτήσεις αερίου της Protergia είναι 210×330, οι Γενικοί Όροι
     * τους A4. Με ένα μέγεθος για όλο το έντυπο οι Όροι θα τεντώνονταν κατά
     * 11% στο ύψος. Οι δικές του σελίδες παίρνουν `page_w`/`page_h`, του
     * `tail` τα `tail_w`/`tail_h` -- κι όπου αυτά λείπουν, τα ίδια με τις
     * δικές του (όπως ως τώρα).
     *
     * @param array<string, mixed> $map
     *
     * @return list<array{path: string, w: float, h: float}>
     */
    public static function pages(string $formsDir, string $key, array $map): array
    {
        $w  = self::mm($map['page_w'] ?? null, 210.0);
        $h  = self::mm($map['page_h'] ?? null, 297.0);
        $tw = self::mm($map['tail_w'] ?? null, $w);
        $th = self::mm($map['tail_h'] ?? null, $h);

        $own = 0;
        while (is_file(self::pagePath($formsDir, $key, $own + 1))) {
            $own++;
        }

        $out = [];
        foreach (self::pagePaths($formsDir, $key, $map) as $i => $path) {
            $tail  = $i >= $own;
            $out[] = ['path' => $path, 'w' => $tail ? $tw : $w, 'h' => $tail ? $th : $h];
        }

        return $out;
    }

    /** Θετικός αριθμός από τον χάρτη, αλλιώς η προεπιλογή. */
    private static function mm(mixed $value, float $default): float
    {
        return (is_int($value) || is_float($value)) && $value > 0 ? (float) $value : $default;
    }

    /**
     * Το κοινό σύνολο σελίδων που ακολουθεί, ή ''.
     *
     * @param array<string, mixed> $map
     */
    public static function tail(string $key, array $map): string
    {
        $tail = $map['tail'] ?? '';

        if (! is_string($tail) || $tail === '' || $tail === $key) {
            return '';
        }

        $provider = self::provider($tail);

        return $provider !== '' && $provider === self::provider($key) ? $tail : '';
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
