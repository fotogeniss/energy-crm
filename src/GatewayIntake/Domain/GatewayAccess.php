<?php

/**
 * Ποιος μπορεί να χτυπήσει την πόρτα του Gateway.
 *
 * Δύο φράχτες, και οι δύο υποχρεωτικοί:
 *  1. Η IP του αιτήματος είναι στη λίστα. Κενή λίστα = κλειστό (fail-closed).
 *  2. Η υπογραφή (`SiteSignature`) με μυστικό που ΔΕΝ είναι του site.
 *     Ίδιο μυστικό θα σήμαινε ότι όποιος έχει το site (Laravel, άλλος server)
 *     μπορεί να γράφει υποψηφίους «από κλήση AI», και αντίστροφα.
 *
 * Καθαρή κλάση, χωρίς WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\GatewayIntake\Domain;

final class GatewayAccess
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $allowed Ακριβείς διευθύνσεις (IPv4 ή IPv6).
     */
    public static function ipAllowed(string $ip, array $allowed): bool
    {
        $ip = self::canonical($ip);
        if ($ip === '') {
            return false;
        }

        foreach ($allowed as $candidate) {
            if (self::canonical($candidate) === $ip) {
                return true;
            }
        }

        return false;
    }

    /**
     * Γιατί η πόρτα είναι κλειστή, ή '' αν είναι ανοιχτή. Για τη σελίδα ρυθμίσεων και το 503.
     *
     * @param list<string> $allowed
     */
    public static function closedBecause(string $secret, string $siteSecret, array $allowed): string
    {
        if ($secret === '') {
            return 'Δεν έχει οριστεί μυστικό για το Gateway.';
        }
        if (strlen($secret) < 32) {
            return 'Το μυστικό του Gateway είναι πολύ μικρό (τουλάχιστον 32 χαρακτήρες).';
        }
        if ($siteSecret !== '' && hash_equals($siteSecret, $secret)) {
            return 'Το μυστικό του Gateway είναι ίδιο με του site. Πρέπει να διαφέρουν.';
        }
        if ($allowed === []) {
            return 'Δεν έχει οριστεί IP για το Gateway.';
        }

        return '';
    }

    /**
     * Η λίστα από σταθερά/μεταβλητή: διευθύνσεις χωρισμένες με κόμμα. Άκυρες πετιούνται.
     *
     * @return list<string>
     */
    public static function parseList(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $ip = self::canonical(trim($part));
            if ($ip !== '') {
                $out[] = $ip;
            }
        }

        return array_values(array_unique($out));
    }

    /** Ίδια διεύθυνση, ίδια γραφή (το IPv6 γράφεται με πολλούς τρόπους). '' αν δεν είναι IP. */
    private static function canonical(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        $packed = inet_pton($ip);

        return $packed === false ? '' : (string) inet_ntop($packed);
    }
}
