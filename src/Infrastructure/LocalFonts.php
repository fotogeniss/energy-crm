<?php

/**
 * The application's typeface, served from the plugin and never from a CDN.
 *
 * ## Why this is a class and not a `<link>`
 *
 * On 2026-08-17 two pages were found loading Inter from Google's font CDN:
 * the signature page and the tracking page. Both are the pages the CUSTOMER
 * sees — not the agent — and the signature page is where they put their name on
 * a contract. Every load sent the customer's IP to Google from inside a
 * personal-data flow, which German courts have held to be a GDPR breach.
 *
 * The rule «the typeface is served locally» has to hold in more than one place,
 * and a rule enforced in one place out of two is not a rule. So it is an object
 * both pages ask, and `NoRemoteFontsTest` fails the build if any file in the
 * plugin ever names a font CDN again.
 *
 * ## Why the pages cannot enqueue it the normal way
 *
 * Neither page is a WordPress template: both echo a whole `<!doctype html>`
 * document and never call `wp_head()`, so `wp_enqueue_style()` has nowhere to
 * print. The CSS goes inline, and the URLs must be absolute because the
 * document has no stylesheet to be relative to.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class LocalFonts
{
    /**
     * The stack every surface uses. Manrope first, then whatever the device has.
     *
     * Manrope was chosen after the proposed Lexend turned out to have NO Greek
     * glyphs at all — measured on the file itself, `greek codepoints: 0`. See
     * CHANGELOG 2026-08-17 (11).
     */
    public const STACK = '"Manrope", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

    /**
     * Subset => the unicode-range that decides when the browser fetches it.
     *
     * The ranges are the point: a Greek page never downloads the Latin file and
     * a Latin one never downloads the Greek. Latin-ext is left out here on
     * purpose — these two pages show a Greek name, a Greek address and Latin
     * digits, and nothing that reaches into it.
     *
     * @var array<string, string>
     */
    private const SUBSETS = [
        'greek' => 'U+0370-0377, U+037A-037F, U+0384-038A, U+038C, U+038E-03A1, U+03A3-03FF',
        'latin' => 'U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, '
            . 'U+0308, U+0329, U+2000-206F, U+2074, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, '
            . 'U+FEFF, U+FFFD',
    ];

    private function __construct()
    {
    }

    /**
     * The `@font-face` rules, with absolute URLs under the plugin.
     *
     * @param string $pluginUrl Trailing-slash plugin URL, i.e. ECRM_URL.
     */
    public static function faceCss(string $pluginUrl): string
    {
        $base = rtrim($pluginUrl, '/') . '/public/assets/fonts/';
        $out  = '';

        foreach (self::SUBSETS as $subset => $range) {
            $url  = $base . 'manrope-' . $subset . '-wght-normal.woff2';
            $out .= '@font-face{font-family:"Manrope";font-style:normal;font-weight:200 800;'
                . 'font-display:swap;src:url("' . $url . '") format("woff2-variations");'
                . 'unicode-range:' . $range . ';}';
        }

        return $out;
    }

    /** The same rules wrapped in a `<style>` element, for pages without wp_head(). */
    public static function styleTag(string $pluginUrl): string
    {
        return '<style>' . self::faceCss($pluginUrl) . '</style>';
    }
}
