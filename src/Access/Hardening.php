<?php

/**
 * (393) Σκλήρυνση του WordPress γύρω από το CRM. Έλεγχος ασφαλείας 15/11/2026
 * (project: claude/energy-crm-security-review-1511.md, ευρήματα 2 και 5).
 *
 * ## Application Passwords μόνο για διαχειριστή
 *
 * Ένας κωδικός εφαρμογής του WordPress μπαίνει στο REST χωρίς cookie, χωρίς
 * nonce, και δεν σβήνει όταν αλλάξει ο κανονικός κωδικός. Μετά από
 * παραβίαση λογαριασμού είναι ο τρόπος να μείνει κανείς μέσα: φτιάχνει έναν,
 * και η αλλαγή κωδικού δεν τον βγάζει. Το CRM δεν τους χρειάζεται για τους
 * συνεργάτες· ο διαχειριστής τους κρατά (π.χ. tools/load-test-appwd.php).
 * Μαζί: στην αλλαγή κωδικού από «Το προφίλ μου → Ασφάλεια» σβήνουν όλοι
 * (`UserSessions::changePassword()`).
 *
 * ## Λίστα χρηστών και XML-RPC κλειστά σε ανώνυμους
 *
 * `/wp-json/wp/v2/users` και `?author=N` δίνουν σε οποιονδήποτε τα ονόματα
 * σύνδεσης, δηλαδή τη μισή δουλειά μιας επίθεσης κωδικών. Το XML-RPC είναι
 * δεύτερη πόρτα σύνδεσης που το CRM δεν χρησιμοποιεί. Και τα δύο ξανανοίγουν
 * με φίλτρο, αν κάποτε χρειαστούν:
 *   add_filter( 'ecrm_close_user_listing', '__return_false' );
 *   add_filter( 'ecrm_close_xmlrpc', '__return_false' );
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use WP_Error;
use WP_REST_Request;
use WP_User;

final class Hardening
{
    public function register(): void
    {
        add_filter('wp_is_application_passwords_available_for_user', [$this, 'appPasswordsFor'], 10, 2);

        if (apply_filters('ecrm_close_user_listing', true)) {
            add_filter('rest_pre_dispatch', [$this, 'refuseUserListing'], 10, 3);
            add_action('template_redirect', [$this, 'refuseAuthorScan'], 1);
        }

        if (apply_filters('ecrm_close_xmlrpc', true)) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', static fn (): array => []);
        }
    }

    /**
     * Κωδικοί εφαρμογής μόνο για όποιον έχει `manage_options`. Το
     * tools/load-test-appwd.php δουλεύει με λογαριασμούς πωλητών· για εκείνο,
     * και μόνο σε τοπικό site, ένα mu-plugin με
     *   add_filter( 'ecrm_app_passwords_for_everyone', '__return_true' );
     */
    public function appPasswordsFor(bool $available, WP_User $user): bool
    {
        if (apply_filters('ecrm_app_passwords_for_everyone', false)) {
            return $available;
        }

        return $available && user_can($user, 'manage_options');
    }

    /**
     * Η λίστα χρηστών του REST μόνο για συνδεδεμένους. Το ίδιο το WordPress
     * (επεξεργαστής, `users/me`) τη ρωτά πάντα ως συνδεδεμένος χρήστης.
     *
     * @param mixed $result
     * @return mixed
     */
    public function refuseUserListing($result, mixed $server, WP_REST_Request $request)
    {
        if ($result !== null || ! self::isUserRoute($request->get_route())) {
            return $result;
        }

        if (is_user_logged_in()) {
            return $result;
        }

        return new WP_Error('rest_forbidden', 'Δεν επιτρέπεται.', ['status' => 401]);
    }

    /** `?author=N` από ανώνυμο: αρχική σελίδα, όχι το όνομα του χρήστη στο URL. */
    public function refuseAuthorScan(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- μόνο έλεγχος ύπαρξης, καμία ενέργεια.
        if (is_user_logged_in() || (! isset($_GET['author']) && ! is_author())) {
            return;
        }

        wp_safe_redirect(home_url('/'), 302);
        exit;
    }

    public static function isUserRoute(string $route): bool
    {
        return preg_match('#^/wp/v2/users(/|$)#', $route) === 1;
    }
}
