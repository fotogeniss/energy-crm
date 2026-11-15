<?php

/**
 * (394) Content-Security-Policy, πρώτη φάση: ΜΟΝΟ καταγραφή.
 *
 * Ο browser παίρνει `Content-Security-Policy-Report-Only`: δεν μπλοκάρει
 * τίποτα, απλώς στέλνει στο `POST /ecrm/v1/csp-report` ό,τι ΘΑ μπλόκαρε. Οι
 * αναφορές μαζεύονται στο Energy CRM → Υγεία. Όταν η λίστα μείνει άδεια για
 * μερικές εβδομάδες κανονικής δουλειάς, η επόμενη φάση αλλάζει το header σε
 * `Content-Security-Policy` (ένα φίλτρο, βλ. παρακάτω).
 *
 * Μόνο στις σελίδες του CRM: η σελίδα με το `[energy_crm_app]`, η σελίδα
 * παρακολούθησης (`?ecrm_track=`) και ο «σύνδεσμός μου» (`?ecrm_intake=`).
 * Το υπόλοιπο site και το wp-admin δεν αγγίζονται.
 *
 * Τα σενάρια που τυπώνει το WordPress (`wp_print_inline_script_tag`, importmap,
 * `<script src>` από enqueue) παίρνουν το nonce με τα φίλτρα
 * `wp_script_attributes` / `wp_inline_script_attributes`. Τα δύο χειρόγραφα
 * `<script>` (tracking, intake) το παίρνουν από το `nonce()`.
 *
 * Διακόπτες (π.χ. σε mu-plugin):
 *   add_filter( 'ecrm_csp_enabled', '__return_false' );  // καθόλου header
 *   add_filter( 'ecrm_csp_enforce', '__return_true' );   // επόμενη φάση: μπλοκάρει
 *
 * Laravel: ένα middleware που προσθέτει το header και ένα `@nonce` στα blade.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Domain\Security\CspPolicy;
use EnergyCRM\Http\Router;

final class ContentSecurityPolicy
{
    public const SHORTCODE = 'energy_crm_app';

    /** Το heic2any (μετατροπή φωτογραφιών iPhone) στις δημόσιες σελίδες. */
    private const PUBLIC_PAGE_SCRIPTS = ['https://cdn.jsdelivr.net'];

    private static string $nonce = '';

    public function register(): void
    {
        add_action('template_redirect', [$this, 'send'], 0);
        add_filter('wp_script_attributes', [self::class, 'withNonce']);
        add_filter('wp_inline_script_attributes', [self::class, 'withNonce']);
    }

    /** Ένα ανά αίτηση, ίδιο για header και σενάρια. */
    public static function nonce(): string
    {
        if (self::$nonce === '') {
            self::$nonce = base64_encode(random_bytes(16));
        }

        return self::$nonce;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function withNonce(array $attributes): array
    {
        if (! isset($attributes['nonce'])) {
            $attributes['nonce'] = self::nonce();
        }

        return $attributes;
    }

    public function send(): void
    {
        if (headers_sent() || ! apply_filters('ecrm_csp_enabled', true)) {
            return;
        }

        $public = self::isPublicPage();

        if (! $public && ! self::isAppPage()) {
            return;
        }

        $name = apply_filters('ecrm_csp_enforce', false)
            ? 'Content-Security-Policy'
            : 'Content-Security-Policy-Report-Only';

        header($name . ': ' . self::policy($public));
    }

    public static function policy(bool $publicPage): string
    {
        return CspPolicy::header(
            self::nonce(),
            esc_url_raw(rest_url(Router::NAMESPACE . '/csp-report')),
            $publicPage ? self::PUBLIC_PAGE_SCRIPTS : []
        );
    }

    private static function isPublicPage(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- μόνο έλεγχος ύπαρξης.
        return isset($_GET['ecrm_track']) || isset($_GET['ecrm_intake']);
    }

    private static function isAppPage(): bool
    {
        if (! is_singular()) {
            return false;
        }

        $post = get_post();

        return $post !== null && has_shortcode((string) $post->post_content, self::SHORTCODE);
    }
}
