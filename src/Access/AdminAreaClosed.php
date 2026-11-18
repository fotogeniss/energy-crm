<?php

/**
 * (414) Το wp-admin κλειστό για όσους δουλεύουν μόνο στο CRM.
 *
 * Οι ρόλοι του CRM έχουν `read`, οπότε ως τώρα ένας πωλητής άνοιγε το
 * `/wp-admin/profile.php` και άλλαζε κωδικό ΧΩΡΙΣ να δώσει τον τωρινό
 * (έλεγχος 17/11, εύρημα 3), παρακάμπτοντας το «Ασφάλεια» του CRM και το
 * όριο λαθών του. Τίποτα στο wp-admin δεν είναι γι' αυτούς: όλες οι σελίδες
 * του Energy CRM εκεί θέλουν `manage_options`.
 *
 * - Όποιος έχει το δικαίωμα του CRM και ΔΕΝ είναι διαχειριστής → στο CRM.
 * - `admin-ajax.php` και `admin-post.php` μένουν ανοιχτά: από εκεί περνούν
 *   το REST nonce (`?action=rest-nonce`) και οι φόρμες.
 * - Χωρίς τη μπάρα του WordPress στο site.
 *
 * Ξανανοίγει: add_filter( 'ecrm_close_admin_area', '__return_false' );
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

final class AdminAreaClosed
{
    public function register(): void
    {
        if (! apply_filters('ecrm_close_admin_area', true)) {
            return;
        }

        add_action('admin_init', [$this, 'sendToCrm'], 1);
        add_filter('show_admin_bar', [$this, 'adminBar'], 20);
    }

    public function sendToCrm(): void
    {
        if (wp_doing_ajax() || self::isAdminPost()) {
            return;
        }

        if (
            ! self::keepsOut(
                current_user_can(Capability::USE_CRM),
                current_user_can('manage_options')
            )
        ) {
            return;
        }

        wp_safe_redirect(home_url('/'));
        exit;
    }

    public function adminBar(bool $show): bool
    {
        return $show && ! self::keepsOut(
            current_user_can(Capability::USE_CRM),
            current_user_can('manage_options')
        );
    }

    /** Ο κανόνας μόνος του, για να ελέγχεται χωρίς WordPress. */
    public static function keepsOut(bool $usesCrm, bool $isAdmin): bool
    {
        return $usesCrm && ! $isAdmin;
    }

    private static function isAdminPost(): bool
    {
        $script = isset($_SERVER['SCRIPT_NAME'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['SCRIPT_NAME']))
            : '';

        return basename($script) === 'admin-post.php';
    }
}
