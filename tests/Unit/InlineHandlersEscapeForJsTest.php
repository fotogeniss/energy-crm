<?php

/**
 * (414) Τιμή μέσα σε inline `onsubmit`/`onclick` της PHP περνά από `esc_js()`,
 * ποτέ από `esc_attr()`/`esc_html()`.
 *
 * Ο browser ξεκωδικοποιεί το attribute πριν τρέξει το JS: το `&#039;` του
 * esc_attr γίνεται ξανά «'» και κλείνει το string. Έτσι ένα όνομα χρήστη
 * έτρεχε κώδικα ως διαχειριστής στις Εκκαθαρίσεις (έλεγχος 17/11).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InlineHandlersEscapeForJsTest extends TestCase
{
    public function testInlineHandlersNeverUseAttributeEscaping(): void
    {
        $root  = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/admin/*.php') ?: [],
            glob($root . '/includes/*.php') ?: [],
            glob($root . '/public/*.php') ?: []
        );
        $bad   = [];

        foreach ($files as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (preg_match('/\son(submit|click|change)="[^"]*\'\s*\.\s*esc_(attr|html)\(/i', $line) === 1) {
                    $bad[] = basename($file) . ':' . ($i + 1);
                }
            }
        }

        self::assertGreaterThan(5, count($files));
        self::assertSame([], $bad, 'esc_attr/esc_html μέσα σε inline JS: ' . implode(', ', $bad));
    }
}
