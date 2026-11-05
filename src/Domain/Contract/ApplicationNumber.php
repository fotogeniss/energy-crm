<?php

/**
 * (349) «Αριθμός Αίτησης»: η αριθμοσειρά του εντύπου του παρόχου
 * (π.χ. 1853-1-0046485), που γράφει ο agent στο βήμα 1 της αίτησης.
 *
 * Ζει στη στήλη `contracts.provider_ref` (40 χαρακτήρες, AddProviderRefColumn)
 * και τυπώνεται στο πεδίο `arithmos_aitisis` του εντύπου. Mockup:
 * docs/UI-APPLICATION-NUMBER.html.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class ApplicationNumber
{
    /** Το μήκος της στήλης. */
    public const MAX = 40;

    private function __construct()
    {
    }

    /**
     * Καθαρό κείμενο ή null όταν είναι κενό.
     *
     * Κρατά γράμματα, αριθμούς, παύλες, κάθετους και τελείες· τα κενά γίνονται
     * ένα. Ό,τι άλλο πετιέται, ώστε στο έντυπο να μη φτάνει ποτέ κάτι που δεν
     * είναι αριθμοσειρά. Κόβεται στους 40 χαρακτήρες της στήλης.
     */
    public static function normalize(string $raw): ?string
    {
        $clean = preg_replace('/[^\p{L}\p{N}\-\/.\s]+/u', '', $raw) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');

        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, self::MAX, 'UTF-8');
    }
}
