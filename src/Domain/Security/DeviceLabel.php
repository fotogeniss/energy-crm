<?php

/**
 * (388) Πώς φαίνεται μια σύνδεση στο «Πού είμαι συνδεδεμένος».
 *
 * Από το User-Agent βγαίνει μόνο «Browser, Σύστημα» (π.χ. «Chrome, Windows»),
 * και η IP φαίνεται μισή. Αρκεί για να αναγνωρίσει κανείς τη συσκευή του·
 * περισσότερα δεν χρειάζεται να εμφανίζονται.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Security;

final class DeviceLabel
{
    /** Σειρά με σημασία: ο Edge και ο Opera λένε και «Chrome», ο Chrome λέει και «Safari». */
    private const BROWSERS = [
        'Edg/'           => 'Edge',
        'OPR/'           => 'Opera',
        'SamsungBrowser' => 'Samsung Internet',
        'Firefox/'       => 'Firefox',
        'FxiOS'          => 'Firefox',
        'CriOS'          => 'Chrome',
        'Chrome/'        => 'Chrome',
        'Safari/'        => 'Safari',
    ];

    /** Σειρά με σημασία: το Android λέει και «Linux», το iPhone λέει και «Mac OS X». */
    private const SYSTEMS = [
        'Android'   => 'Android',
        'iPhone'    => 'iPhone',
        'iPad'      => 'iPad',
        'Windows'   => 'Windows',
        'Macintosh' => 'macOS',
        'CrOS'      => 'ChromeOS',
        'Linux'     => 'Linux',
    ];

    private function __construct()
    {
    }

    public static function from(string $userAgent): string
    {
        $browser = self::first(self::BROWSERS, $userAgent);
        $system  = self::first(self::SYSTEMS, $userAgent);

        if ($browser === '' && $system === '') {
            return 'Άγνωστη συσκευή';
        }

        return implode(', ', array_filter([$browser, $system]));
    }

    /** «Κινητό» για τα εικονίδια: ό,τι δεν είναι υπολογιστής. */
    public static function isMobile(string $userAgent): bool
    {
        return preg_match('/Android|iPhone|iPad|Mobile/', $userAgent) === 1;
    }

    /** Η μισή IP: «85.74.•••.•••», ή οι δύο πρώτες ομάδες μιας IPv6. */
    public static function maskIp(string $ip): string
    {
        $ip = trim($ip);

        if ($ip === '') {
            return '';
        }

        if (str_contains($ip, ':')) {
            return implode(':', array_slice(explode(':', $ip), 0, 2)) . ':•••';
        }

        $p = explode('.', $ip);

        return count($p) === 4 ? $p[0] . '.' . $p[1] . '.•••.•••' : '•••';
    }

    /** @param array<string, string> $map */
    private static function first(array $map, string $userAgent): string
    {
        foreach ($map as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                return $label;
            }
        }

        return '';
    }
}
