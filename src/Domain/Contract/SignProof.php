<?php

/**
 * (354b) Υπογραφή με ΑΦΜ και αποδεικτικό υπογραφής: οι κανόνες, χωρίς WordPress.
 *
 * Mockup: docs/UI-SIGN-AFM.html, εγκρίθηκε 11/11/2026 («μια χαρά είναι»).
 *
 * Ο πελάτης βλέπει την πορεία της αίτησης χωρίς ΑΦΜ. Για να ανοίξει το
 * πλαίσιο υπογραφής γράφει πρώτα το ΑΦΜ του· αν ταιριάζει, ο server δίνει μια
 * «απόδειξη επιβεβαίωσης» (HMAC, 30 λεπτά) που η υπογραφή πρέπει να φέρει. Η
 * υπογραφή χωρίς αυτήν αρνείται -- το κουμπί στη σελίδα δεν είναι ο φύλακας.
 *
 * Εδώ ζουν μόνο καθαρές συναρτήσεις: ποιο ΑΦΜ περιμένουμε για ποιον ρόλο, πώς
 * συγκρίνεται, πώς φτιάχνεται και διαβάζεται η απόδειξη, πώς κρύβεται ΑΦΜ και
 * IP στο αποδεικτικό, και τι συσκευή γράφεται. Τα κλειδιά, οι ώρες και η
 * αποθήκευση έρχονται απ' έξω.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

final class SignProof
{
    /** Πόσο ισχύει η επιβεβαίωση ΑΦΜ πριν ζητηθεί ξανά. */
    public const TTL_SECONDS = 1800;

    /** Λάθος προσπάθειες πριν κλειδώσει ο σύνδεσμος. */
    public const MAX_FAILS = 5;

    /** Πόσο μένει κλειδωμένος. */
    public const LOCK_SECONDS = 900;

    /** Ο τύπος αρχείου του αποδεικτικού, ανά ρόλο. */
    private const KINDS = [
        SignatureRoles::MOBILE => 'sign_proof',
        SignatureRoles::ENERGY => 'sign_proof_energy',
    ];

    public static function kindFor(string $role): string
    {
        return self::KINDS[$role] ?? '';
    }

    /** @return list<string> */
    public static function kinds(): array
    {
        return array_values(self::KINDS);
    }

    /**
     * Ταιριάζει; Και τα δύο πρέπει να είναι ήδη μόνο ψηφία (ο καλών τα περνά
     * από το ECRM_Validate::digits(), τον ΕΝΑ κανόνα για το ΑΦΜ --
     * AfmIsNormalisedInOnePlaceTest) και 9 στον αριθμό· ένα κενό αναμενόμενο
     * ΑΦΜ δεν ταιριάζει ποτέ με τίποτα.
     */
    public static function afmMatches(string $typed, string $expected): bool
    {
        return self::isAfm($expected) && self::isAfm($typed) && hash_equals($expected, $typed);
    }

    public static function isAfm(string $afm): bool
    {
        return strlen($afm) === 9 && ctype_digit($afm);
    }

    /**
     * Το ΑΦΜ που περιμένουμε από αυτόν τον ρόλο.
     *
     * Ο κύριος ρόλος (SignatureRoles::primaryRoleFor) είναι ο πελάτης της
     * αίτησης. Ο δεύτερος, σε COMBO με διαφορετικό πρόσωπο, έχει το δικό του
     * ΑΦΜ στα extras (`combo_energy_afm` / `combo_mobile_afm`), όπως το τυπώνει
     * και το έντυπο του παρόχου (ProviderFormFields).
     *
     * @param array<string, mixed> $contract `afm` και `energy_type`, αποκρυπτογραφημένα.
     * @param array<string, mixed> $extra    Τα extras, αποκρυπτογραφημένα.
     * @param list<string>         $required Οι ρόλοι που πρέπει να υπογράψουν.
     */
    public static function expectedAfm(array $contract, array $extra, string $role, array $required): string
    {
        $primary = SignatureRoles::primaryRoleFor((string) ($contract['energy_type'] ?? ''), $required);

        if ($role === $primary || count($required) < 2) {
            return trim((string) ($contract['afm'] ?? ''));
        }

        $key = $role === SignatureRoles::ENERGY ? 'combo_energy_afm' : 'combo_mobile_afm';

        return trim((string) ($extra[$key] ?? ''));
    }

    /**
     * Η απόδειξη επιβεβαίωσης: «χρόνος.υπογραφή». Ο χρόνος είναι Unix
     * timestamp· η υπογραφή δένει χρόνο, σύμβαση και ρόλο με το κλειδί της
     * σύμβασης, οπότε δεν μεταφέρεται σε άλλη αίτηση ή σε άλλο πρόσωπο.
     */
    public static function makeProof(int $contractId, string $role, int $time, string $key): string
    {
        return $time . '.' . self::mac($contractId, $role, $time, $key);
    }

    /**
     * Η ώρα επιβεβαίωσης αν η απόδειξη είναι έγκυρη και φρέσκια, αλλιώς null.
     */
    public static function readProof(string $proof, int $contractId, string $role, string $key, int $now): ?int
    {
        if ($key === '' || ! preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $proof, $m)) {
            return null;
        }

        $time = (int) $m[1];

        if ($time > $now + 60 || $now - $time > self::TTL_SECONDS) {
            return null;
        }

        return hash_equals(self::mac($contractId, $role, $time, $key), $m[2]) ? $time : null;
    }

    /** «••••••781»: φαίνονται μόνο τα τρία τελευταία. */
    public static function maskAfm(string $afm): string
    {
        $afm = trim($afm);

        return $afm === '' ? '—' : str_repeat('•', max(0, mb_strlen($afm) - 3)) . mb_substr($afm, -3);
    }

    /**
     * «85.72.•••.•••» -- ίδιος κανόνας με την καρτέλα αίτησης (maskIp() στο
     * ecrm-view-detail.js, απόφαση ιδιοκτήτη 21/08).
     */
    public static function maskIp(string $ip): string
    {
        $ip = trim($ip);

        if ($ip === '') {
            return '—';
        }

        if (str_contains($ip, ':')) {
            return implode(':', array_slice(explode(':', $ip), 0, 2)) . ':•••';
        }

        $parts = explode('.', $ip);

        return count($parts) === 4 ? $parts[0] . '.' . $parts[1] . '.•••.•••' : '•••';
    }

    /**
     * «iPhone · Safari 18» από το User-Agent. Χοντρικά, όχι πλήρης ανάλυση:
     * αρκεί για να διαβάσει κάποιος «από τι συσκευή υπέγραψε».
     */
    public static function deviceLabel(string $ua): string
    {
        $os = match (true) {
            str_contains($ua, 'iPhone')  => 'iPhone',
            str_contains($ua, 'iPad')    => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS')  => 'Mac',
            str_contains($ua, 'Linux')   => 'Linux',
            default                      => '',
        };

        $browser = '';

        foreach (
            [
                'Edge'             => '/Edg(?:A|iOS)?\/(\d+)/',
                'Samsung Internet' => '/SamsungBrowser\/(\d+)/',
                'Opera'            => '/OPR\/(\d+)/',
                'Firefox'          => '/(?:Firefox|FxiOS)\/(\d+)/',
                'Chrome'           => '/(?:Chrome|CriOS)\/(\d+)/',
                'Safari'           => '/Version\/(\d+)[.\d]* (?:Mobile\/\S+ )?Safari/',
            ] as $name => $pattern
        ) {
            if (preg_match($pattern, $ua, $m)) {
                $browser = $name . ' ' . $m[1];
                break;
            }
        }

        $label = implode(' · ', array_filter([$os, $browser]));

        return $label !== '' ? $label : 'Άγνωστη συσκευή';
    }

    /** «2026-11-11 15:40:37» → «11/11/2026 15:40:37», χωρίς ζώνη ώρας. */
    public static function stamp(string $sql): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/', $sql, $m)) {
            return '';
        }

        return $m[3] . '/' . $m[2] . '/' . $m[1] . ' ' . $m[4] . ':' . $m[5] . ':' . ($m[6] ?? '00');
    }

    private static function mac(int $contractId, string $role, int $time, string $key): string
    {
        return hash_hmac('sha256', 'afm|' . $contractId . '|' . $role . '|' . $time, $key);
    }
}
