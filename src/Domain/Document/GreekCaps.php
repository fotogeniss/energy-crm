<?php

/**
 * (376) Ελληνικά κεφαλαία ΧΩΡΙΣ τόνους, σε ένα σημείο.
 *
 * Κανόνας ιδιοκτήτη (13/11/2026): «στα κεφαλαία δεν βάζουμε ποτέ τόνους».
 * Το `mb_strtoupper` τους κρατά («Τιμή» → «ΤΙΜΉ»), και το ΐ/ΰ το βγάζει ως
 * Ϊ/Ϋ με ξεχωριστό τόνο. Ό,τι τυπώνεται με κεφαλαία από την PHP (PDF,
 * Excel) περνά από εδώ. Στην οθόνη το κάνει ο browser (`text-transform`
 * με lang="el"), και στο JS το `upper()` του ecrm-format.js.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Document;

final class GreekCaps
{
    private const TONOS = [
        'Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω',
        // Το ΐ/ΰ: ανάλογα με την έκδοση του mbstring βγαίνει ως Ϊ/Ϋ + τόνος ή
        // ως Ι/Υ + διαλυτικά + τόνος (ξεχωριστοί χαρακτήρες). Και τα δύο
        // γίνονται το ενιαίο Ϊ/Ϋ, για να συγκρίνονται ίδια.
        "Ι\u{0308}\u{0301}" => 'Ϊ', "Υ\u{0308}\u{0301}" => 'Ϋ',
        "Ι\u{0308}" => 'Ϊ', "Υ\u{0308}" => 'Ϋ',
        "\u{0301}" => '',
    ];

    private function __construct()
    {
    }

    /** «Τιμή πρότασης» → «ΤΙΜΗ ΠΡΟΤΑΣΗΣ». */
    public static function upper(string $text): string
    {
        return strtr(mb_strtoupper($text, 'UTF-8'), self::TONOS);
    }
}
