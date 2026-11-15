<?php

/**
 * (394) Κάθε χειρόγραφο `<script>` στις σελίδες του CRM έχει το nonce της
 * Content-Security-Policy. Χωρίς αυτό, όταν η CSP περάσει από καταγραφή σε
 * μπλοκάρισμα, η σελίδα του πελάτη θα έμενε λευκή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EveryInlineScriptHasTheNonceTest extends TestCase
{
    public function testNoScriptTagWithoutNonce(): void
    {
        $root    = dirname(__DIR__, 2);
        $missing = [];
        $files   = array_merge(glob($root . '/public/*.php') ?: [], glob($root . '/includes/*.php') ?: []);

        foreach ($files as $file) {
            foreach (file($file) ?: [] as $i => $line) {
                if (preg_match('/<script(?![^>]*\bnonce=)/i', $line) === 1) {
                    $missing[] = basename($file) . ':' . ($i + 1);
                }
            }
        }

        self::assertGreaterThan(5, count($files));
        self::assertSame([], $missing, 'Χειρόγραφο <script> χωρίς nonce: ' . implode(', ', $missing));
    }
}
