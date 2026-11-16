<?php

/**
 * (406) Η σύνδεση σε δύο βήματα ενός χρήστη: ρύθμιση, έλεγχος, συσκευές.
 *
 * ΠΑΝΤΑ προαιρετική: την ενεργοποιεί μόνο όποιος τη θέλει, από το «Το
 * προφίλ μου → Ασφάλεια» (απόφαση ιδιοκτήτη 16/11). Κανείς δεν υποχρεώνεται.
 *
 * Όλα σε user meta, τίποτα σε νέο πίνακα:
 *
 * - `ecrm_2fa_secret`  το μυστικό, κρυπτογραφημένο (FieldCipher, ίδιο κλειδί
 *                      με τα προσωπικά δεδομένα). Χωρίς libsodium δεν
 *                      ενεργοποιείται τίποτα: καλύτερα «δεν γίνεται» παρά
 *                      μυστικό σε απλό κείμενο.
 * - `ecrm_2fa_pending` το μυστικό της ρύθμισης που δεν επιβεβαιώθηκε ακόμη.
 * - `ecrm_2fa_since`   πότε ενεργοποιήθηκε (ώρα site).
 * - `ecrm_2fa_step`    το τελευταίο βήμα TOTP που δεκτήκαμε (κατά της επανάληψης).
 * - `ecrm_2fa_backup`  τα hash των εφεδρικών κωδικών.
 * - `ecrm_2fa_devices` συσκευές που «θυμόμαστε»: μόνο HMAC του token, δεμένο
 *                      με το hash του κωδικού πρόσβασης. Αλλαγή κωδικού =
 *                      όλες ξεχνιούνται, χωρίς να τις ψάξει κανείς.
 * - `ecrm_2fa_used`    πότε περάστηκε τελευταία φορά δεύτερο βήμα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Domain\Security\BackupCodes;
use EnergyCRM\Domain\Security\DeviceLabel;
use EnergyCRM\Domain\Security\Totp;
use WP_User;

final class TwoFactor
{
    public const ISSUER = 'Energy CRM';

    public const COOKIE = 'ecrm_2fa_rd';

    private const SECRET  = 'ecrm_2fa_secret';
    private const PENDING = 'ecrm_2fa_pending';
    private const SINCE   = 'ecrm_2fa_since';
    private const STEP    = 'ecrm_2fa_step';
    private const BACKUP  = 'ecrm_2fa_backup';
    private const DEVICES = 'ecrm_2fa_devices';
    private const USED    = 'ecrm_2fa_used';

    /** Πόσες μέρες θυμάται μια συσκευή (απόφαση ιδιοκτήτη 16/11). */
    public const REMEMBER_DAYS = 30;

    /** Όριο συσκευών ανά χρήστη· οι παλιότερες φεύγουν. */
    private const MAX_DEVICES = 10;

    public function __construct(private readonly FieldCipher $cipher)
    {
    }

    public static function make(): self
    {
        return new self(new FieldCipher(wp_salt('secure_auth')));
    }

    public static function available(): bool
    {
        return FieldCipher::isAvailable();
    }

    public function isEnabled(int $userId): bool
    {
        return $userId > 0 && (string) get_user_meta($userId, self::SECRET, true) !== '';
    }

    /**
     * Νέο μυστικό για ρύθμιση. Δεν ισχύει πριν το confirm().
     *
     * @return array{secret: string, grouped: string, uri: string}
     */
    public function start(WP_User $user): array
    {
        $secret = Totp::newSecret();
        update_user_meta($user->ID, self::PENDING, $this->cipher->encrypt($secret));

        return [
            'secret'  => $secret,
            'grouped' => Totp::grouped($secret),
            'uri'     => Totp::uri($secret, (string) $user->user_email, self::ISSUER),
        ];
    }

    /**
     * Ο αριθμός ταιριάζει με το μυστικό της ρύθμισης: ενεργοποίηση και
     * εφεδρικοί κωδικοί (επιστρέφονται ΜΙΑ φορά), αλλιώς null.
     *
     * @return list<string>|null
     */
    public function confirm(int $userId, string $code): ?array
    {
        $pending = $this->cipher->decrypt((string) get_user_meta($userId, self::PENDING, true));

        if ($pending === '') {
            return null;
        }

        $step = Totp::verify($pending, $code, time());

        if ($step === null) {
            return null;
        }

        update_user_meta($userId, self::SECRET, $this->cipher->encrypt($pending));
        update_user_meta($userId, self::STEP, $step);
        update_user_meta($userId, self::SINCE, current_time('mysql'));
        delete_user_meta($userId, self::PENDING);

        return $this->newBackupCodes($userId);
    }

    /** Αριθμός από την εφαρμογή· κάθε βήμα γίνεται δεκτό μία φορά. */
    public function verify(int $userId, string $code): bool
    {
        $secret = $this->cipher->decrypt((string) get_user_meta($userId, self::SECRET, true));

        if ($secret === '') {
            return false;
        }

        $step = Totp::verify($secret, $code, time(), (int) get_user_meta($userId, self::STEP, true));

        if ($step === null) {
            return false;
        }

        update_user_meta($userId, self::STEP, $step);
        update_user_meta($userId, self::USED, current_time('mysql'));

        return true;
    }

    /** Εφεδρικός κωδικός: δεκτός μία φορά, μετά σβήνεται. */
    public function useBackup(int $userId, string $code): bool
    {
        $hashes = $this->backupHashes($userId);
        $index  = BackupCodes::match($code, $hashes);

        if ($index === null) {
            return false;
        }

        unset($hashes[$index]);
        update_user_meta($userId, self::BACKUP, array_values($hashes));
        update_user_meta($userId, self::USED, current_time('mysql'));

        return true;
    }

    /** @return list<string> */
    public function newBackupCodes(int $userId): array
    {
        $codes = BackupCodes::generate();
        update_user_meta($userId, self::BACKUP, array_map([BackupCodes::class, 'hash'], $codes));

        return $codes;
    }

    public function disable(int $userId): void
    {
        $keys = [self::SECRET, self::PENDING, self::SINCE, self::STEP, self::BACKUP, self::DEVICES, self::USED];

        foreach ($keys as $key) {
            delete_user_meta($userId, $key);
        }
    }

    /**
     * Για την οθόνη «Ασφάλεια».
     *
     * @return array{enabled: bool, available: bool, since: string,
     *     backup_left: int, last_used: string, devices: list<array<string, mixed>>}
     */
    public function status(WP_User $user, string $cookie = ''): array
    {
        $current = $this->deviceIdFromCookie($user->ID, $cookie);

        return [
            'enabled'     => $this->isEnabled($user->ID),
            'available'   => self::available(),
            'since'       => self::dayMonthYear((string) get_user_meta($user->ID, self::SINCE, true)),
            'backup_left' => count($this->backupHashes($user->ID)),
            'last_used'   => self::dayMonthYear((string) get_user_meta($user->ID, self::USED, true), true),
            'devices'     => array_map(
                static fn (array $d): array => [
                    'id'      => $d['id'],
                    'label'   => $d['label'],
                    'mobile'  => $d['mobile'],
                    'until'   => wp_date('d/m/Y', (int) $d['until']),
                    'current' => $d['id'] === $current,
                ],
                $this->devices($user->ID)
            ),
        ];
    }

    /**
     * «Να με θυμάται»: νέα συσκευή, και η τιμή του cookie (μία φορά).
     */
    public function remember(WP_User $user, string $userAgent): string
    {
        $id    = bin2hex(random_bytes(6));
        $token = bin2hex(random_bytes(24));
        $list  = $this->devices($user->ID);

        array_unshift($list, [
            'id'     => $id,
            'h'      => $this->deviceHash($user, $token),
            'label'  => DeviceLabel::from($userAgent),
            'mobile' => DeviceLabel::isMobile($userAgent),
            'until'  => time() + self::REMEMBER_DAYS * DAY_IN_SECONDS,
        ]);

        update_user_meta($user->ID, self::DEVICES, array_slice($list, 0, self::MAX_DEVICES));

        return $user->ID . '.' . $id . '.' . $token;
    }

    /** Το cookie αντιστοιχεί σε ζωντανή συσκευή αυτού του χρήστη με τον τωρινό κωδικό. */
    public function isRemembered(WP_User $user, string $cookie): bool
    {
        $parts = explode('.', $cookie);

        if (count($parts) !== 3 || (int) $parts[0] !== $user->ID) {
            return false;
        }

        foreach ($this->devices($user->ID) as $device) {
            if ($device['id'] === $parts[1] && hash_equals($device['h'], $this->deviceHash($user, $parts[2]))) {
                return true;
            }
        }

        return false;
    }

    public function forgetDevice(int $userId, string $id): bool
    {
        $list = $this->devices($userId);
        $kept = array_values(array_filter($list, static fn (array $d): bool => $d['id'] !== $id));

        update_user_meta($userId, self::DEVICES, $kept);

        return count($kept) !== count($list);
    }

    public function forgetDevices(int $userId): void
    {
        delete_user_meta($userId, self::DEVICES);
    }

    /** @return list<string> */
    private function backupHashes(int $userId): array
    {
        $stored = get_user_meta($userId, self::BACKUP, true);

        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    /**
     * Ζωντανές συσκευές (οι ληγμένες πετιούνται στο διάβασμα).
     *
     * @return list<array{id: string, h: string, label: string, mobile: bool, until: int}>
     */
    private function devices(int $userId): array
    {
        $stored = get_user_meta($userId, self::DEVICES, true);
        $now    = time();
        $out    = [];

        foreach (is_array($stored) ? $stored : [] as $d) {
            if (! is_array($d) || (int) ($d['until'] ?? 0) <= $now || ! isset($d['id'], $d['h'])) {
                continue;
            }

            $out[] = [
                'id'     => (string) $d['id'],
                'h'      => (string) $d['h'],
                'label'  => (string) ($d['label'] ?? ''),
                'mobile' => ! empty($d['mobile']),
                'until'  => (int) $d['until'],
            ];
        }

        return $out;
    }

    private function deviceIdFromCookie(int $userId, string $cookie): string
    {
        $parts = explode('.', $cookie);

        return count($parts) === 3 && (int) $parts[0] === $userId ? $parts[1] : '';
    }

    /** Δεμένο με το hash του κωδικού: νέος κωδικός, άκυρες όλες οι συσκευές. */
    private function deviceHash(WP_User $user, string $token): string
    {
        return hash_hmac('sha256', $token . '|' . $user->user_pass, wp_salt('auth'));
    }

    private static function dayMonthYear(string $mysql, bool $withTime = false): string
    {
        if ($mysql === '') {
            return '';
        }

        $time = strtotime($mysql . ' UTC');

        return $time === false ? '' : gmdate($withTime ? 'd/m/Y H:i' : 'd/m/Y', $time);
    }
}
