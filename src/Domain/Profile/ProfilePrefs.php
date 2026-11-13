<?php

/**
 * (379) Τι μπορεί να ρυθμίσει ο ίδιος ο χρήστης στο «Το προφίλ μου».
 *
 * Μόνο οι κανόνες: ποια κλειδιά υπάρχουν, ποιες τιμές δέχονται, ποια είναι
 * η προεπιλογή, και πότε είναι «ώρες ησυχίας». Πού αποθηκεύονται: στο
 * `Infrastructure\UserProfile`. docs/UI-PROFILE.html (εγκρίθηκε 13/11/2026).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Profile;

use DateTimeInterface;

final class ProfilePrefs
{
    /** Οθόνες που μπορούν να ανοίγουν πρώτες. */
    public const START_VIEWS = ['dashboard', 'contracts', 'tasks', 'leads', 'customers', 'new-contract'];

    public const FONTS = ['normal', 'large'];

    public const SCOPES = ['', 'own', 'team'];

    /** Ώρες ησυχίας: από 21:00 ως 08:00, και όλη την Κυριακή. */
    private const QUIET_FROM = 21;

    private const QUIET_TO = 8;

    private const MAX_FAVOURITES = 20;

    private function __construct()
    {
    }

    /**
     * @return array{
     *   phone: string, title: string, signature: string, start_view: string, scope: string,
     *   font: string, notify_status_email: bool, notify_digest_email: bool, quiet: bool,
     *   fav_providers: list<int>
     * }
     */
    public static function defaults(): array
    {
        return [
            'phone'               => '',
            'title'               => '',
            'signature'           => '',
            'start_view'          => 'dashboard',
            'scope'               => '',
            'font'                => 'normal',
            'notify_status_email' => true,
            'notify_digest_email' => true,
            'quiet'               => false,
            'fav_providers'       => [],
        ];
    }

    /**
     * Ό,τι αποθηκευμένο ή σταλμένο, καθαρισμένο πάνω στις προεπιλογές.
     * Άγνωστα κλειδιά πετιούνται· άκυρες τιμές γυρίζουν στην προεπιλογή.
     *
     * @param array<string, mixed> $input
     * @return array{
     *   phone: string, title: string, signature: string, start_view: string, scope: string,
     *   font: string, notify_status_email: bool, notify_digest_email: bool, quiet: bool,
     *   fav_providers: list<int>
     * }
     */
    public static function clean(array $input): array
    {
        $out = self::defaults();

        $out['phone']     = self::text($input['phone'] ?? '', 30);
        $out['title']     = self::text($input['title'] ?? '', 60);
        $out['signature'] = self::text($input['signature'] ?? '', 500, true);

        $start = (string) ($input['start_view'] ?? '');
        $out['start_view'] = in_array($start, self::START_VIEWS, true) ? $start : 'dashboard';

        $scope = (string) ($input['scope'] ?? '');
        $out['scope'] = in_array($scope, self::SCOPES, true) ? $scope : '';

        $font = (string) ($input['font'] ?? '');
        $out['font'] = in_array($font, self::FONTS, true) ? $font : 'normal';

        foreach (['notify_status_email', 'notify_digest_email', 'quiet'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $out[$flag] = filter_var($input[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $favs = is_array($input['fav_providers'] ?? null) ? $input['fav_providers'] : [];
        $favs = array_filter(array_map('intval', $favs), static fn (int $id): bool => $id > 0);
        $favs = array_values(array_unique($favs));
        $out['fav_providers'] = array_slice($favs, 0, self::MAX_FAVOURITES);

        return $out;
    }

    /** Ώρες ησυχίας τώρα; (τοπική ώρα του site) */
    public static function isQuietTime(DateTimeInterface $localNow): bool
    {
        if ((int) $localNow->format('N') === 7) {
            return true;
        }

        $hour = (int) $localNow->format('G');

        return $hour >= self::QUIET_FROM || $hour < self::QUIET_TO;
    }

    private static function text(mixed $value, int $max, bool $multiline = false): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = $multiline
            ? (string) preg_replace("/[^\\S\n]+/u", ' ', str_replace("\r", '', $text))
            : (string) preg_replace('/\s+/u', ' ', $text);

        return mb_substr(trim($text), 0, $max);
    }
}
