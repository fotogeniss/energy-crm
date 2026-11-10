<?php

/**
 * (353) Ποια εμφάνιση βλέπει ένας χρήστης: η κλασική ή η νέα «Signature».
 *
 * Η νέα εμφάνιση στήνεται οθόνη-οθόνη πίσω από διακόπτη. Όσο είναι σε δοκιμή
 * τη βλέπει ΜΟΝΟ διαχειριστής: για όλους τους άλλους η απάντηση είναι πάντα
 * «classic», ακόμα κι αν έμεινε τιμή στη βάση. Όταν τελειώσουν όλες οι
 * οθόνες, αλλάζει μόνο το `mayTry()`. docs/UI-REDESIGN-WOW.html.
 *
 * Ίδιο σχήμα με το `ThemePreference`: ένα κλειδί user meta, γνωστό μόνο εδώ,
 * διαβασμένο από την PHP πριν τυπωθεί το HTML (κανένα τρεμόπαιγμα).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class UiPreference
{
    public const CLASSIC   = 'classic';
    public const SIGNATURE = 'signature';

    private const META_KEY = 'ecrm_ui';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return [self::CLASSIC, self::SIGNATURE];
    }

    /** Όσο η νέα εμφάνιση είναι σε δοκιμή: μόνο ο διαχειριστής. */
    public static function mayTry(int $userId): bool
    {
        return $userId > 0 && user_can($userId, 'manage_options');
    }

    public static function forUser(int $userId): string
    {
        if (! self::mayTry($userId)) {
            return self::CLASSIC;
        }

        $stored = get_user_meta($userId, self::META_KEY, true);

        return $stored === self::SIGNATURE ? self::SIGNATURE : self::CLASSIC;
    }

    /** Αποθηκεύει και επιστρέφει ό,τι ισχύει μετά. */
    public static function save(int $userId, string $ui): string
    {
        if (self::mayTry($userId) && in_array($ui, self::allowed(), true)) {
            update_user_meta($userId, self::META_KEY, $ui);
        }

        return self::forUser($userId);
    }
}
