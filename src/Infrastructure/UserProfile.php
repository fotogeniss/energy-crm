<?php

/**
 * (379) Το «Το προφίλ μου» ενός χρήστη: όνομα (του WordPress) και οι
 * προτιμήσεις του (`Domain\Profile\ProfilePrefs`), σε ένα κλειδί user meta.
 *
 * Ίδιο σχήμα με `ThemePreference`/`UiPreference`: γνωστό μόνο εδώ. Το email
 * δεν αλλάζει από εδώ: είναι και το όνομα σύνδεσης, το αλλάζει ο διαχειριστής.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use DateTimeImmutable;
use EnergyCRM\Domain\Profile\ProfilePrefs;

final class UserProfile
{
    private const META_KEY = 'ecrm_profile';

    private function __construct()
    {
    }

    /**
     * Οι προτιμήσεις, καθαρισμένες.
     *
     * @return array{
     *   phone: string, title: string, signature: string, start_view: string, scope: string,
     *   font: string, notify_status_email: bool, notify_digest_email: bool, quiet: bool,
     *   fav_providers: list<int>
     * }
     */
    public static function prefs(int $userId): array
    {
        if ($userId <= 0) {
            return ProfilePrefs::defaults();
        }

        $stored = get_user_meta($userId, self::META_KEY, true);

        return ProfilePrefs::clean(is_array($stored) ? $stored : []);
    }

    /**
     * Όλο το προφίλ, για την οθόνη.
     *
     * @return array<string, mixed>
     */
    public static function forUser(int $userId): array
    {
        $user = get_userdata($userId);

        return array_merge(self::prefs($userId), [
            'first_name'   => $user ? (string) $user->first_name : '',
            'last_name'    => $user ? (string) $user->last_name : '',
            'display_name' => $user ? (string) $user->display_name : '',
            'email'        => $user ? (string) $user->user_email : '',
        ]);
    }

    /**
     * Γράφει ό,τι στάλθηκε πάνω στο αποθηκευμένο και γυρίζει το νέο προφίλ.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public static function save(int $userId, array $patch): array
    {
        $prefs = ProfilePrefs::clean(array_merge(self::prefs($userId), $patch));
        update_user_meta($userId, self::META_KEY, $prefs);

        if (isset($patch['first_name']) || isset($patch['last_name'])) {
            $user  = get_userdata($userId);
            $first = self::name($patch['first_name'] ?? ($user ? $user->first_name : ''));
            $last  = self::name($patch['last_name'] ?? ($user ? $user->last_name : ''));

            if ($first !== '' || $last !== '') {
                wp_update_user([
                    'ID'           => $userId,
                    'first_name'   => $first,
                    'last_name'    => $last,
                    'display_name' => trim($first . ' ' . $last),
                ]);
            }
        }

        return self::forUser($userId);
    }

    /**
     * Θέλει ο χρήστης αυτό το email τώρα; Η προτίμησή του, και οι ώρες
     * ησυχίας αν τις έχει ανοίξει.
     */
    public static function wantsEmail(int $userId, string $flag): bool
    {
        $prefs = self::prefs($userId);

        if (! isset($prefs[$flag]) || $prefs[$flag] !== true) {
            return false;
        }

        if ($prefs['quiet']) {
            $now = new DateTimeImmutable('now', wp_timezone());

            return ! ProfilePrefs::isQuietTime($now);
        }

        return true;
    }

    /**
     * Ό,τι χρειάζεται η σελίδα πριν φορτώσει (window.ECRM.prefs).
     *
     * @return array{start_view: string, scope: string, font: string, fav_providers: list<int>}
     */
    public static function clientPrefs(int $userId): array
    {
        $p = self::prefs($userId);

        return [
            'start_view'    => $p['start_view'],
            'scope'         => $p['scope'],
            'font'          => $p['font'],
            'fav_providers' => $p['fav_providers'],
        ];
    }

    private static function name(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) preg_replace('/\s+/u', ' ', (string) $value)) : '';

        return mb_substr(sanitize_text_field($text), 0, 60);
    }
}
