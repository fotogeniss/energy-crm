<?php

/**
 * (395) Κανένα `onclick="…"` ή inline `<script>` μέσα σε HTML που φτιάχνει
 * η JavaScript της εφαρμογής (παράθυρα εκτύπωσης, αφίσα QR, σελίδα εκτός
 * σύνδεσης). Η Content-Security-Policy (394) τα μπλοκάρει όταν ενεργοποιηθεί·
 * τα κουμπιά δένονται με `addEventListener` (βλ. `openBlobPage()`).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NoInlineHandlersInScriptsTest extends TestCase
{
    public function testGeneratedHtmlHasNoInlineCode(): void
    {
        $files   = glob(dirname(__DIR__, 2) . '/public/assets/*.js') ?: [];
        $missing = [];

        foreach ($files as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                $code = preg_replace('#^\s*(//|\*|/\*).*$#', '', $line);

                if (preg_match('/\son[a-z]+\s*=\s*\\\\?["\']|<script|javascript:/i', (string) $code) === 1) {
                    $missing[] = basename($file) . ':' . ($i + 1);
                }
            }
        }

        self::assertGreaterThan(10, count($files));
        self::assertSame([], $missing, 'Inline κώδικας σε HTML της JS: ' . implode(', ', $missing));
    }
}
