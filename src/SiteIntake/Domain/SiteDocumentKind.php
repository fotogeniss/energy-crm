<?php

/**
 * Τα είδη εγγράφων του site → τα είδη του CRM (`ECRM_Docs::kinds()`).
 *
 * Ανά είδος, σε δεδομένα, όχι σε `if` σκορπισμένα στον controller (κανόνας 3
 * του ARCHITECTURE «Ετοιμότητα για integrations»).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\SiteIntake\Domain;

final class SiteDocumentKind
{
    private const MAP = [
        'id_front' => 'id_card',
        'id_back'  => 'id_card',
        'bill'     => 'provider_bill',
        // «Μισθωτήριο ή Ε9»: το site δεν ξέρει ποιο από τα δύο ανέβηκε· το
        // «Αποδεικτικό κατοικίας» τα καλύπτει και τα δύο, και ο πωλητής το
        // αλλάζει με ένα κλικ αν χρειαστεί.
        'proof'    => 'residence',
    ];

    private function __construct()
    {
    }

    public static function toCrm(string $siteKind): string
    {
        return self::MAP[$siteKind] ?? 'other';
    }
}
