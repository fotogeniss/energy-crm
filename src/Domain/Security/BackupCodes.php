<?php

/**
 * (406) Εφεδρικοί κωδικοί για όποιον χάσει το κινητό.
 *
 * Οκτώ κωδικοί «1234-5678», ο καθένας μία φορά. Φαίνονται μόνο τη στιγμή
 * που φτιάχνονται· στη βάση μένει μόνο το hash τους (password_hash), όπως
 * ένας κωδικός πρόσβασης.
 *
 * Καθαρή κλάση, χωρίς WordPress.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Security;

final class BackupCodes
{
    public const COUNT = 8;

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function generate(int $count = self::COUNT): array
    {
        $out = [];

        while (count($out) < $count) {
            $code = sprintf('%04d-%04d', random_int(0, 9999), random_int(0, 9999));

            if (! in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }

    /** Μόνο τα ψηφία: «4821 7730», «4821-7730» και «48217730» είναι το ίδιο. */
    public static function normalise(string $code): string
    {
        return (string) preg_replace('/\D/', '', $code);
    }

    public static function hash(string $code): string
    {
        return password_hash(self::normalise($code), PASSWORD_DEFAULT);
    }

    /**
     * Η θέση του hash που ταιριάζει, ή null.
     *
     * @param list<string> $hashes
     */
    public static function match(string $code, array $hashes): ?int
    {
        $digits = self::normalise($code);

        if (strlen($digits) !== 8) {
            return null;
        }

        foreach ($hashes as $i => $hash) {
            if (password_verify($digits, $hash)) {
                return $i;
            }
        }

        return null;
    }
}
