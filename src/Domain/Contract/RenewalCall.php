<?php

/**
 * (344) Η «Κλήση ανανέωσης»: η εργασία που φτιάχνεται έναν μήνα πριν λήξει
 * μια σύμβαση. Για σύμβαση 12 μηνών είναι ο έλεγχος του 11ου μήνα.
 *
 * Εδώ μόνο το κείμενο και η προτεραιότητα· ποιες συμβάσεις και πότε, στο
 * Persistence\RenewalCallRepository. Mockup: docs/UI-QR-AND-RENEWAL-CALL.html.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class RenewalCall
{
    /** Η τιμή της στήλης tasks.kind. */
    public const KIND = 'renewal_call';

    /** Τελευταία εβδομάδα: υψηλή προτεραιότητα. */
    private const URGENT_DAYS = 7;

    private function __construct()
    {
    }

    /**
     * «Κλήση ανανέωσης: Παπαδόπουλος Γ. · λήγει 30/11». Χωρίς όνομα πελάτη,
     * ο κωδικός της σύμβασης.
     *
     * @param string $endDate Y-m-d
     */
    public static function title(string $customer, string $code, string $endDate): string
    {
        $who  = trim($customer) !== '' ? trim($customer) : $code;
        $when = preg_match('/^\d{4}-(\d{2})-(\d{2})/', $endDate, $m) === 1 ? $m[2] . '/' . $m[1] : $endDate;

        return 'Κλήση ανανέωσης: ' . $who . ' · λήγει ' . $when;
    }

    /**
     * Το τρέχον πρόγραμμα και η πρόταση, όπως στις «Λήξεις».
     *
     * @param array<string, mixed>|null $offer Από το Domain\Pricing\RenewalOffer::for(), ή null
     *                                         όταν δεν ξέρουμε κατανάλωση.
     */
    public static function note(string $program, ?array $offer): string
    {
        $parts = [];

        if (trim($program) !== '') {
            $parts[] = 'Τώρα: ' . trim($program);
        }

        $state = is_array($offer) ? (string) ($offer['state'] ?? '') : '';

        if ($state === 'cheaper' && is_array($offer['best'] ?? null)) {
            $saving  = isset($offer['saving']) ? (float) $offer['saving'] : 0.0;
            $parts[] = 'Πρόταση: ' . (string) $offer['best']['name']
                . ($saving > 0 ? ', −€' . number_format($saving, 0, ',', '.') . '/έτος' : '');
        } elseif ($state === 'best') {
            $parts[] = 'Είναι ήδη το φθηνότερο που έχουμε — πρότεινε ανανέωση στο ίδιο';
        } else {
            $parts[] = 'Χωρίς πρόταση: λείπει η κατανάλωση — δες Λήξεις';
        }

        return implode(' · ', $parts);
    }

    public static function priority(int $daysLeft): string
    {
        return $daysLeft <= self::URGENT_DAYS ? 'high' : 'normal';
    }
}
