<?php

/**
 * (406) Το δεύτερο βήμα της σύνδεσης, στη σελίδα σύνδεσης του WordPress.
 *
 * Μόνο για όποιον ενεργοποίησε μόνος του τη σύνδεση σε δύο βήματα (ΠΑΝΤΑ
 * προαιρετική, απόφαση ιδιοκτήτη 16/11). Όλοι οι άλλοι δεν βλέπουν τίποτα
 * διαφορετικό.
 *
 * Ροή:
 *
 * 1. `authenticate` (προτεραιότητα 99, μετά τον κωδικό, το LoginThrottle και
 *    τους απενεργοποιημένους): ο κωδικός ήταν σωστός. Αν ο χρήστης έχει δύο
 *    βήματα και η συσκευή δεν είναι «θυμημένη», η σύνδεση ΔΕΝ ολοκληρώνεται:
 *    φτιάχνεται ένα εισιτήριο 10 λεπτών (transient, μόνο το hash του) και
 *    δείχνεται η οθόνη του αριθμού. Μόνο στη φόρμα σύνδεσης (`login_init`):
 *    REST με Application Password δεν περνά από εδώ.
 * 2. `login_form_ecrm_2fa`: έλεγχος αριθμού ή εφεδρικού. Σωστός → cookie
 *    σύνδεσης, `wp_login`, προαιρετικά «θυμήσου τη συσκευή». Λάθος → μετράει
 *    στο LoginThrottle (ίδιο κλείδωμα με τον κωδικό)· 5 λάθη ακυρώνουν το
 *    εισιτήριο.
 *
 * Η σελίδα δεν έχει JavaScript.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use EnergyCRM\Infrastructure\TwoFactor;
use WP_Error;
use WP_User;

final class TwoFactorLogin
{
    public const ACTION = 'ecrm_2fa';

    private const TICKET = 'ecrm_2fa_t_';

    private const TICKET_TTL = 600;

    private const MAX_TRIES = 5;

    private ?TwoFactor $twoFactor = null;

    /** Το TwoFactor φτιάχνεται όταν χρειαστεί: εδώ το wp_salt() μπορεί να μην υπάρχει ακόμη. */
    public function __construct(private readonly LoginThrottle $throttle)
    {
    }

    private function twoFactor(): TwoFactor
    {
        return $this->twoFactor ??= TwoFactor::make();
    }

    public function register(): void
    {
        add_filter('authenticate', [$this, 'intercept'], 99, 1);
        add_action('login_form_' . self::ACTION, [$this, 'handle']);
    }

    /**
     * @param mixed $user
     * @return mixed
     */
    public function intercept($user)
    {
        if (! $user instanceof WP_User || ! did_action('login_init') || ! $this->twoFactor()->isEnabled($user->ID)) {
            return $user;
        }

        if ($this->twoFactor()->isRemembered($user, self::cookie())) {
            return $user;
        }

        $token = bin2hex(random_bytes(24));

        // phpcs:disable WordPress.Security.NonceVerification -- wp-login.php does not nonce its own form.
        $remember = ! empty($_POST['rememberme']);
        $redirect = isset($_REQUEST['redirect_to']) ? esc_url_raw(wp_unslash((string) $_REQUEST['redirect_to'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification

        set_transient(self::TICKET . hash('sha256', $token), [
            'user'     => $user->ID,
            'remember' => $remember,
            'redirect' => $redirect,
            'tries'    => 0,
        ], self::TICKET_TTL);

        $this->render($user, $token, '');
        exit;
    }

    public function handle(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the single-use ticket is the CSRF token.
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD']))
            : '';

        if ($method !== 'post' || empty($_POST['ecrm_2fa_t'])) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        $token  = sanitize_text_field(wp_unslash((string) $_POST['ecrm_2fa_t']));
        $code   = sanitize_text_field(wp_unslash((string) ($_POST['ecrm_2fa_code'] ?? '')));
        $backup = sanitize_text_field(wp_unslash((string) ($_POST['ecrm_2fa_backup'] ?? '')));
        $keep   = ! empty($_POST['ecrm_2fa_keep']);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $key    = self::TICKET . hash('sha256', $token);
        $ticket = get_transient($key);
        $user   = is_array($ticket) ? get_userdata((int) ($ticket['user'] ?? 0)) : false;

        if (! $user instanceof WP_User) {
            $this->expired();
            exit;
        }

        if ($this->throttle->refuseWhenLocked($user, $user->user_login) instanceof WP_Error) {
            delete_transient($key);
            $this->expired('Πάρα πολλές λάθος προσπάθειες. Δοκίμασε ξανά σε λίγο.');
            exit;
        }

        $ok = $backup !== ''
            ? $this->twoFactor()->useBackup($user->ID, $backup)
            : $this->twoFactor()->verify($user->ID, $code);

        if (! $ok) {
            // Το ίδιο γεγονός με λάθος κωδικό: μετράει στο LoginThrottle και σε ό,τι άλλο ακούει.
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
            do_action('wp_login_failed', $user->user_login, new WP_Error('ecrm_2fa', 'Λάθος αριθμός.'));
            $ticket['tries'] = (int) $ticket['tries'] + 1;

            if ($ticket['tries'] >= self::MAX_TRIES) {
                delete_transient($key);
                $this->expired('Πάρα πολλές λάθος προσπάθειες. Ξεκίνα από την αρχή.');
                exit;
            }

            set_transient($key, $ticket, self::TICKET_TTL);
            $this->render($user, $token, $backup !== ''
                ? 'Ο εφεδρικός κωδικός δεν ισχύει ή έχει ήδη χρησιμοποιηθεί.'
                : 'Λάθος αριθμός. Δοκίμασε τον τωρινό από την εφαρμογή.');
            exit;
        }

        delete_transient($key);

        if ($keep) {
            setcookie(TwoFactor::COOKIE, $this->twoFactor()->remember($user, self::userAgent()), [
                'expires'  => time() + TwoFactor::REMEMBER_DAYS * DAY_IN_SECONDS,
                // Όλο το site: η σελίδα σύνδεσης και η εφαρμογή μπορεί να ζουν σε άλλη διαδρομή.
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }

        wp_set_auth_cookie($user->ID, ! empty($ticket['remember']), is_ssl());
        // Η σύνδεση ολοκληρώθηκε τώρα, όχι στο πρώτο βήμα: το wp_login εδώ, όπως το wp_signon().
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
        do_action('wp_login', $user->user_login, $user);

        $requested = (string) ($ticket['redirect'] ?? '');
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
        $redirect  = apply_filters('login_redirect', $requested !== '' ? $requested : admin_url(), $requested, $user);

        wp_safe_redirect(wp_validate_redirect((string) $redirect, home_url('/')));
        exit;
    }

    private static function cookie(): string
    {
        return isset($_COOKIE[TwoFactor::COOKIE])
            ? sanitize_text_field(wp_unslash((string) $_COOKIE[TwoFactor::COOKIE]))
            : '';
    }

    private static function userAgent(): string
    {
        return isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT']))
            : '';
    }

    private function expired(string $why = 'Το βήμα έληξε. Συνδέσου ξανά.'): void
    {
        $this->page(
            '<h1>' . esc_html($why) . '</h1>'
            . '<a class="e2-btn" href="' . esc_url(wp_login_url()) . '">Στη σύνδεση</a>'
        );
    }

    private function render(WP_User $user, string $token, string $error): void
    {
        $initials = mb_strtoupper(
            mb_substr((string) $user->first_name, 0, 1) . mb_substr((string) $user->last_name, 0, 1)
        );
        $initials = $initials !== '' ? $initials : mb_strtoupper(mb_substr((string) $user->display_name, 0, 2));

        $this->page(
            '<span class="e2-shield" aria-hidden="true"><svg viewBox="0 0 24 24">'
            . '<path d="M12 3l7 3v5c0 4.5-3 8.4-7 10-4-1.6-7-5.5-7-10V6z"/>'
            . '<path d="M8.5 12l2.4 2.4L15.5 9.8"/></svg></span>'
            . '<span class="e2-who"><i>' . esc_html($initials) . '</i>'
            . esc_html((string) $user->user_email) . '</span>'
            . '<h1>Επιβεβαίωση</h1><p>Γράψε τον αριθμό από το Authenticator.</p>'
            . '<form method="post" action="' . esc_url(add_query_arg('action', self::ACTION, wp_login_url())) . '">'
            . '<input type="hidden" name="ecrm_2fa_t" value="' . esc_attr($token) . '">'
            . '<input class="e2-code' . ($error !== '' ? ' is-bad' : '') . '" name="ecrm_2fa_code" '
            . 'inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7" '
            . 'placeholder="••••••" aria-label="Αριθμός 6 ψηφίων" autofocus>'
            . ($error !== '' ? '<div class="e2-err" role="alert">' . esc_html($error) . '</div>' : '')
            . '<label class="e2-keep"><input type="checkbox" name="ecrm_2fa_keep" value="1" checked>'
            . '<span class="e2-sw"></span>'
            . 'Να με θυμάται αυτή η συσκευή για ' . TwoFactor::REMEMBER_DAYS . ' ημέρες</label>'
            . '<button class="e2-btn" type="submit">Σύνδεση</button>'
            . '<details class="e2-alt"><summary>Δεν έχω το κινητό μου</summary>'
            . '<p>Γράψε έναν από τους εφεδρικούς κωδικούς σου.</p>'
            . '<input class="e2-backup" name="ecrm_2fa_backup" inputmode="numeric" autocomplete="off" '
            . 'placeholder="1234-5678" maxlength="9" aria-label="Εφεδρικός κωδικός">'
            . '<button class="e2-btn e2-btn--ghost" type="submit">Σύνδεση με εφεδρικό</button></details>'
            . '</form>'
        );
    }

    /** Η σελίδα: σκοτεινή σκηνή, κάρτα από γυαλί (docs/UI-2FA.html, Δ). Χωρίς scripts. */
    private function page(string $inner): void
    {
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');

        $html = '<!doctype html><html lang="el"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>Επιβεβαίωση · Energy CRM</title><style>' . self::css() . '</style></head>'
            . '<body><main class="e2-card"><div class="e2-brand"><i>⚡</i>Energy CRM</div>'
            . $inner
            . '</main></body></html>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where each piece is built; the CSS is ours.
        echo $html;
    }

    /** Από αρχείο, για να ισχύουν οι κανόνες χρωμάτων των υπόλοιπων CSS· inline, χωρίς δεύτερο αίτημα. */
    private static function css(): string
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/ecrm-2fa-login.css');

        // Μέσα σε <style>: τίποτα που θα έκλεινε το στοιχείο.
        return str_replace('</', '<\\/', $css);
    }
}
