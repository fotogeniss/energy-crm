<?php

/**
 * (388) Οι συνδέσεις ενός χρήστη και η αλλαγή κωδικού. docs/UI-PROFILE-SECURITY.html.
 *
 * Οι συνδέσεις είναι αυτές που κρατά ήδη το WordPress (`session_tokens`
 * user meta: ώρα σύνδεσης, λήξη, IP, User-Agent), με κλειδί το «verifier»
 * (sha256 του token του cookie). Το verifier φεύγει προς τον browser ως
 * αναγνωριστικό: από αυτό δεν φτιάχνεται cookie.
 *
 * Η αλλαγή κωδικού κρατά ΤΗΝ ΙΔΙΑ σύνδεση (ίδιο token): το cookie
 * ξαναγράφεται, γιατί περιέχει κομμάτι του hash του κωδικού, αλλά το token
 * μένει, άρα και το nonce του REST που κρατά η σελίδα. Οι άλλες συνδέσεις
 * κλείνουν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Domain\Security\DeviceLabel;
use EnergyCRM\Domain\Security\PasswordPolicy;
use WP_Application_Passwords;
use WP_Session_Tokens;
use WP_User;

final class UserSessions
{
    public const CHANGED_META = 'ecrm_pw_changed_at';

    /** Λάθος τωρινός κωδικός: τόσες φορές σε τόσα λεπτά, μετά αναμονή. */
    public const MAX_FAILS = 5;

    public const FAIL_WINDOW = 15 * 60;

    public const TOO_MANY = 'Πολλές λάθος προσπάθειες. Δοκίμασε ξανά σε λίγα λεπτά.';

    private const FAIL_KEY = 'ecrm_pwfail_';

    private const META = 'session_tokens';

    /**
     * (414) Ένας μετρητής για κάθε «απόδειξε ότι είσαι εσύ» μέσα στη σύνδεση:
     * τωρινός κωδικός (αλλαγή κωδικού, απενεργοποίηση δύο βημάτων) και αριθμός
     * της εφαρμογής (νέοι εφεδρικοί, απενεργοποίηση). Πριν, μόνο η αλλαγή
     * κωδικού μετρούσε· το `/me/2fa/disable` δεχόταν απεριόριστες δοκιμές
     * κωδικού, και με κλεμμένη συνεδρία έδινε τον κωδικό χωρίς όριο.
     */
    public static function tooManyFailures(int $userId): bool
    {
        return (int) get_transient(self::FAIL_KEY . $userId) >= self::MAX_FAILS;
    }

    public static function recordFailure(int $userId): void
    {
        $key   = self::FAIL_KEY . $userId;
        $fails = (int) get_transient($key);

        set_transient($key, $fails + 1, self::FAIL_WINDOW);
    }

    public static function clearFailures(int $userId): void
    {
        delete_transient(self::FAIL_KEY . $userId);
    }

    /**
     * @return list<array{id: string, device: string, mobile: bool, ip: string, login: string, current: bool}>
     */
    public function list(int $userId): array
    {
        $current = $this->currentVerifier();
        $rows    = [];
        $order   = [];

        foreach ($this->raw($userId) as $verifier => $session) {
            if ((int) ($session['expiration'] ?? 0) < time()) {
                continue;
            }

            $ua     = (string) ($session['ua'] ?? '');
            $login  = (int) ($session['login'] ?? 0);
            $isMe   = (string) $verifier === $current;
            $rows[] = [
                'id'      => (string) $verifier,
                'device'  => DeviceLabel::from($ua),
                'mobile'  => DeviceLabel::isMobile($ua),
                'ip'      => DeviceLabel::maskIp((string) ($session['ip'] ?? '')),
                'login'   => (string) wp_date('d/m/Y H:i', $login),
                'current' => $isMe,
            ];
            // Η τρέχουσα πρώτη, μετά οι νεότερες.
            $order[] = $isMe ? PHP_INT_MAX : $login;
        }

        array_multisort($order, SORT_DESC, SORT_NUMERIC, $rows);

        return $rows;
    }

    /** Κλείνει μία σύνδεση (όχι την τρέχουσα: για εκείνη υπάρχει «Αποσύνδεση»). */
    public function destroy(int $userId, string $verifier): bool
    {
        $all = $this->raw($userId);

        if ($verifier === $this->currentVerifier() || ! isset($all[$verifier])) {
            return false;
        }

        unset($all[$verifier]);
        $this->store($userId, $all);

        return true;
    }

    /** Κλείνει όλες εκτός από την τρέχουσα· πόσες έκλεισαν. */
    public function destroyOthers(int $userId): int
    {
        $others = count(array_filter($this->list($userId), static fn (array $s): bool => ! $s['current']));

        WP_Session_Tokens::get_instance($userId)->destroy_others(wp_get_session_token());

        return $others;
    }

    /**
     * @return array{ok: bool, error?: string, status?: int, closed?: int}
     */
    public function changePassword(WP_User $user, string $current, string $new): array
    {
        if (self::tooManyFailures($user->ID)) {
            return [
                'ok'     => false,
                'status' => 429,
                'error'  => self::TOO_MANY,
            ];
        }

        if (! wp_check_password($current, $user->user_pass, $user->ID)) {
            self::recordFailure($user->ID);

            return ['ok' => false, 'status' => 400, 'error' => 'Ο τωρινός κωδικός δεν είναι σωστός.'];
        }

        if ($new === $current) {
            return ['ok' => false, 'status' => 400, 'error' => 'Ο νέος κωδικός είναι ίδιος με τον τωρινό.'];
        }

        $problem = PasswordPolicy::problem($new, [
            (string) $user->user_email,
            (string) $user->user_login,
            (string) $user->display_name,
            (string) $user->first_name,
            (string) $user->last_name,
        ]);

        if ($problem !== null) {
            return ['ok' => false, 'status' => 400, 'error' => $problem];
        }

        self::clearFailures($user->ID);

        $manager  = WP_Session_Tokens::get_instance($user->ID);
        $token    = wp_get_session_token();
        $session  = $manager->get($token);
        $remember = is_array($session)
            && ((int) ($session['expiration'] ?? 0) - (int) ($session['login'] ?? 0)) > 2 * DAY_IN_SECONDS;
        $closed   = $this->destroyOthers($user->ID);

        wp_set_password($new, $user->ID);

        // (393) Και οι κωδικοί εφαρμογής: δεν σβήνουν μόνοι τους με την αλλαγή
        // κωδικού, και είναι ακριβώς ο τρόπος να μείνει μέσα όποιος τον έκλεψε.
        if (class_exists(WP_Application_Passwords::class)) {
            WP_Application_Passwords::delete_all_application_passwords($user->ID);
        }

        // Αν η έκδοση του WordPress έκλεισε όλες τις συνδέσεις μαζί με τον
        // κωδικό, η τρέχουσα ξαναμπαίνει ίδια: ίδιο token, η σελίδα μένει
        // συνδεδεμένη και το nonce της ισχύει.
        if (is_array($session) && $manager->get($token) === null) {
            $manager->update($token, $session);
        }

        // Μόνο όταν υπάρχει σύνδεση με cookie: χωρίς token, το WordPress θα
        // άνοιγε καινούρια σύνδεση αντί να κρατήσει την υπάρχουσα.
        if ($token !== '') {
            wp_set_auth_cookie($user->ID, $remember, is_ssl(), $token);
        }
        update_user_meta($user->ID, self::CHANGED_META, time());

        do_action('ecrm_password_changed', $user->ID);

        return ['ok' => true, 'closed' => $closed];
    }

    public function changedAt(int $userId): string
    {
        $at = (int) get_user_meta($userId, self::CHANGED_META, true);

        return $at > 0 ? (string) wp_date('d/m/Y', $at) : '';
    }

    private function currentVerifier(): string
    {
        $token = wp_get_session_token();

        return $token === '' ? '' : hash('sha256', $token);
    }

    /** @return array<string, array<string, mixed>> */
    private function raw(int $userId): array
    {
        $all = get_user_meta($userId, self::META, true);

        return is_array($all) ? $all : [];
    }

    /** @param array<string, array<string, mixed>> $all */
    private function store(int $userId, array $all): void
    {
        if ($all === []) {
            delete_user_meta($userId, self::META);

            return;
        }

        update_user_meta($userId, self::META, $all);
    }
}
