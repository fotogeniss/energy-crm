<?php

/**
 * (319) Το κείμενο των «Παρατηρήσεων» της αίτησης.
 *
 * Η Protergia θέλει την ειδική κατηγορία (ΚΟΤ Α/Β/Γ, Ευάλωτος) στις
 * Παρατηρήσεις και όχι δίπλα στην «Κατηγορία Παροχής», και μαζί ό,τι
 * προωθητική ενέργεια ισχύει (π.χ. μηδενική εγγύηση). Μία γραμμή το καθένα,
 * επιλογή ιδιοκτήτη· ό,τι είναι κενό δεν αφήνει κενή γραμμή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class Remarks
{
    private function __construct()
    {
    }

    public static function lines(string $specialCategory, string $promotion): string
    {
        $out = [];

        $special = self::clean($specialCategory);
        if ($special !== '') {
            $out[] = 'Ειδική κατηγορία: ' . $special;
        }

        $promo = self::clean($promotion);
        if ($promo !== '') {
            $out[] = 'Προωθητική ενέργεια: ' . $promo;
        }

        return implode("\n", $out);
    }

    /**
     * Οι γραμμές μιας τιμής για την εκτύπωση.
     *
     * Με `$multiline` κάθε γραμμή χωριστά· αλλιώς μία, με « · » εκεί που
     * ήταν η αλλαγή γραμμής, ώστε μια θέση χωρίς διάστιχο να μη γράφει ποτέ
     * τη δεύτερη γραμμή πάνω στην πρώτη.
     *
     * @return list<string>
     */
    public static function split(string $value, bool $multiline): array
    {
        $parts = array_values(array_filter(
            array_map('trim', preg_split('/\R/u', $value) ?: []),
            static fn (string $p): bool => $p !== ''
        ));

        if ($parts === []) {
            return [];
        }

        return $multiline ? $parts : [implode(' · ', $parts)];
    }

    /** Μία γραμμή: ό,τι αλλαγή γραμμής έγραψε ο χρήστης γίνεται κενό. */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
