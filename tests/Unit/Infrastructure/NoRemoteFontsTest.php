<?php

/**
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use EnergyCRM\Infrastructure\LocalFonts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The typeface is served from the plugin, and this is what keeps it that way.
 *
 * A `<link>` to a font CDN is one line, looks harmless in review, and works
 * perfectly — which is exactly why it came back once already. What it also does
 * is send the visitor's IP to a third party, and on the signature page that
 * visitor is a customer in the middle of signing a contract.
 *
 * So the rule is not a note in a document: it is a test that reads every source
 * file in the plugin and fails if one of them names a font CDN.
 */
final class NoRemoteFontsTest extends TestCase
{
    /** Hosts that serve fonts to a browser. Add to this list, never remove. */
    private const FONT_CDN_HOSTS = [
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'use.typekit.net',
        'fonts.bunny.net',
        'cdn.jsdelivr.net/npm/@fontsource',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every file the plugin ships to a browser or renders into one.
     *
     * @return list<array{string}>
     */
    public static function sourceFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $out  = [];

        // Ο iterator δίνει ήδη SplFileInfo, οπότε ΔΕΝ μπαίνει instanceof: το
        // phpstan το βλέπει ως πάντα-αληθές και κοκκινίζει.
        foreach (['public', 'includes', 'admin', 'src'] as $dir) {
            /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $it */
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js', 'css'], true)) {
                    continue;
                }

                $out[] = [substr($file->getPathname(), strlen($root) + 1)];
            }
        }

        return $out;
    }

    /**
     * The guard. One file at a time so a failure names the offender.
     */
    #[DataProvider('sourceFiles')]
    public function testNoSourceFileLoadsAFontFromACdn(string $relative): void
    {
        $body = (string) file_get_contents(self::root() . '/' . $relative);

        foreach (self::FONT_CDN_HOSTS as $host) {
            self::assertStringNotContainsString(
                $host,
                $body,
                $relative . ' loads a font from ' . $host . '. Serve it from public/assets/fonts '
                . 'through EnergyCRM\Infrastructure\LocalFonts instead — a visitor IP sent to a font '
                . 'CDN from a page handling ΑΦΜ, ΑΔΤ or a signature is a GDPR incident, not a style choice.'
            );
        }
    }

    /** The files the CSS points at must actually be in the package. */
    public function testTheFontFilesAreShipped(): void
    {
        foreach (['greek', 'latin'] as $subset) {
            self::assertFileExists(
                self::root() . '/public/assets/fonts/manrope-' . $subset . '-wght-normal.woff2',
                'The ' . $subset . ' subset is referenced by LocalFonts but missing from the package'
            );
        }
    }

    /** Every URL the class emits stays under the plugin it was given. */
    public function testEveryUrlStaysInsideThePlugin(): void
    {
        $css = LocalFonts::faceCss('https://example.test/wp-content/plugins/energy-crm/');

        preg_match_all('#url\("([^"]+)"\)#', $css, $m);

        self::assertNotEmpty($m[1], 'faceCss() emitted no font URL at all');

        foreach ($m[1] as $url) {
            self::assertStringStartsWith(
                'https://example.test/wp-content/plugins/energy-crm/public/assets/fonts/',
                $url
            );
        }
    }

    /**
     * A trailing slash on the plugin URL is the caller's business, not ours.
     * ECRM_URL has one; a caller passing it without must not produce `...crmpublic`.
     */
    public function testASlashIsNotRequiredFromTheCaller(): void
    {
        self::assertSame(
            LocalFonts::faceCss('https://example.test/p/'),
            LocalFonts::faceCss('https://example.test/p')
        );
    }

    /** Both subsets ship a unicode-range, or the browser downloads both files always. */
    public function testBothSubsetsAreScopedByUnicodeRange(): void
    {
        $css = LocalFonts::faceCss('https://example.test/p/');

        self::assertSame(2, substr_count($css, '@font-face'));
        self::assertSame(2, substr_count($css, 'unicode-range:'));
        self::assertStringContainsString('U+0370-0377', $css, 'the Greek range is missing');
    }
}
