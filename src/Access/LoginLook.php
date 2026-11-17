<?php

/**
 * (412) Η σελίδα σύνδεσης στην ταυτότητα του 410.
 *
 * Ο ιδιοκτήτης (17/11, screenshot κινητού): «και το login έχει κρατήσει την
 * παλιά εμφάνιση, φτιάξ' το με την καινούργια». Το `wp-login.php` το έβαφε
 * το θέμα (navy/amber, «⚡» ως λογότυπο). Τώρα το βάφει το plugin, ώστε να
 * ταξιδεύει μαζί του και στο νέο host, με το σύμβολο του 410 και την όψη
 * της οθόνης του δεύτερου βήματος (`TwoFactorLogin`). Μακέτα: docs/UI-LOGIN.html.
 *
 * Το CSS κερδίζει το θέμα με πιο συγκεκριμένους κανόνες
 * (`body.login.ecrm-login`), χωρίς να το πειράζει.
 *
 * Κλείνει, αν ποτέ χρειαστεί, με:
 *   add_filter( 'ecrm_login_look', '__return_false' );
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

final class LoginLook
{
    public const BODY_CLASS = 'ecrm-login';

    public function register(): void
    {
        if (! apply_filters('ecrm_login_look', true)) {
            return;
        }

        add_action('login_enqueue_scripts', [$this, 'enqueue']);
        add_filter('login_body_class', [$this, 'bodyClass']);
        add_filter('login_headerurl', static fn (): string => home_url('/'), 20);
        add_filter('login_headertext', static fn (): string => 'Energy CRM', 20);
        add_action('login_head', [$this, 'themeColor']);
    }

    public function enqueue(): void
    {
        wp_enqueue_style(
            'ecrm-login',
            (\EnergyCRM\Plugin::instance()?->url() ?? '') . 'public/assets/ecrm-login.css',
            [],
            \ECRM_Shortcodes::asset_version('ecrm-login.css')
        );
    }

    /**
     * @param array<int, string> $classes
     * @return array<int, string>
     */
    public function bodyClass(array $classes): array
    {
        $classes[] = self::BODY_CLASS;

        return $classes;
    }

    /** Η μπάρα του κινητού σκούρα, όπως το manifest (411). */
    public function themeColor(): void
    {
        echo '<meta name="theme-color" content="#0d0e0c">' . "\n";
    }
}
