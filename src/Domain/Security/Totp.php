<?php

/**
 * (406) Κωδικοί μίας χρήσης με βάση τον χρόνο (TOTP, RFC 6238).
 *
 * Ό,τι κάνουν το Google και το Microsoft Authenticator: HMAC-SHA1 του
 * μετρητή «βήματα των 30″ από το 1970» με το κοινό μυστικό, 6 ψηφία. Δεκτό
 * και ένα βήμα πριν ή μετά, για μικρή διαφορά ρολογιού κινητού/server.
 *
 * `verify()` επιστρέφει το βήμα που ταίριαξε, ώστε ο καλών να το κρατήσει
 * και να μη δεχτεί τον ίδιο αριθμό δεύτερη φορά (επανάληψη).
 *
 * Καθαρή κλάση, χωρίς WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Security;

final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /** Βήματα ανοχής πριν και μετά (±30″). */
    public const WINDOW = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private function __construct()
    {
    }

    /** Νέο μυστικό: 20 τυχαία bytes (160 bit, όπως προτείνει το RFC 4226), σε Base32. */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Ο αριθμός για ένα βήμα. */
    public static function code(string $secret, int $step): string
    {
        $key = self::base32Decode($secret);

        if ($key === '') {
            return '';
        }

        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $hash    = hash_hmac('sha1', $counter, $key, true);
        $offset  = ord($hash[19]) & 0x0F;
        $value   = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function stepAt(int $unixTime): int
    {
        return intdiv($unixTime, self::PERIOD);
    }

    /**
     * Το βήμα που ταιριάζει με τον αριθμό, ή null. Βήματα έως και
     * `$lastStep` απορρίπτονται: ο ίδιος αριθμός δεν περνά δεύτερη φορά.
     */
    public static function verify(string $secret, string $code, int $unixTime, int $lastStep = -1): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';

        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $now = self::stepAt($unixTime);

        for ($delta = -self::WINDOW; $delta <= self::WINDOW; $delta++) {
            $step = $now + $delta;

            if ($step <= $lastStep) {
                continue;
            }

            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** Ο σύνδεσμος που διαβάζει η εφαρμογή από τον QR. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    /** Για πληκτρολόγηση: ομάδες των 4 («JBSW Y3DP …»). */
    public static function grouped(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $bytes === '' ? '' : $out;
    }

    /** '' για ό,τι δεν είναι έγκυρο Base32 (κενά και πεζά γίνονται δεκτά). */
    public static function base32Decode(string $text): string
    {
        $text = strtoupper((string) preg_replace('/[\s=-]/', '', $text));

        if ($text === '' || strspn($text, self::ALPHABET) !== strlen($text)) {
            return '';
        }

        $bits = '';

        foreach (str_split($text) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }
}
