<?php

/**
 * Η υπογραφή των αιτημάτων του site σύγκρισης τιμών.
 *
 * Το site και το CRM μοιράζονται ένα μυστικό. Κάθε αίτημα του site κουβαλά:
 *
 *   X-ECRM-Timestamp: 1791840000
 *   X-ECRM-Signature: hex( HMAC-SHA256( μυστικό, timestamp + "\n" + σώμα ) )
 *
 * Γιατί HMAC και όχι σκέτο κλειδί σε header: το κλειδί ΔΕΝ ταξιδεύει ποτέ.
 * Αν κάποιος δει ένα αίτημα (log proxy, λάθος ρύθμιση HTTPS), δεν παίρνει
 * τίποτα που να του επιτρέπει να φτιάξει δικό του. Το timestamp κλείνει την
 * επανάληψη ενός παλιού αιτήματος: πέντε λεπτά και μετά δεν περνά. Μέσα στα
 * πέντε λεπτά η επανάληψη δεν κάνει ζημιά, γιατί και τα δύο endpoints είναι
 * idempotent (ίδιο `ref` → ίδιος υποψήφιος, ίδιο έγγραφο → δεν ξαναμπαίνει).
 *
 * Καθαρή κλάση, χωρίς WordPress: τρέχει σε unit test, και μεταφέρεται
 * αυτούσια σε Laravel (το site την έχει ήδη, σε PHP, απέναντι).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\SiteIntake\Domain;

final class SiteSignature
{
    /** Πόσο παλιό (ή πόσο «μελλοντικό», για ρολόγια που διαφέρουν) αίτημα δεχόμαστε. */
    public const MAX_SKEW_SECONDS = 300;

    private function __construct()
    {
    }

    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . "\n" . $body, $secret);
    }

    public static function verify(string $secret, string $timestamp, string $signature, string $body, int $now): bool
    {
        if ($secret === '' || $timestamp === '' || ! ctype_digit($timestamp) || strlen($signature) !== 64) {
            return false;
        }

        if (abs($now - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($secret, (int) $timestamp, $body), strtolower($signature));
    }
}
