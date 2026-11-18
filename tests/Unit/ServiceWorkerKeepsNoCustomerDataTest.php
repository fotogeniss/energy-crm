<?php

/**
 * (407) Ο service worker κρατά μόνο ό,τι δεν είναι στοιχείο πελάτη.
 *
 * Απόφαση Ζ (11/09, GDPR) και ιδιοκτήτης 16/11: χωρίς σήμα ανοίγουν ο
 * κατάλογος παρόχων, η Βάση Γνώσης και τα μαθήματα, τίποτα από πελάτες,
 * συμβάσεις, προμήθειες ή ομάδα. Κάθε διαδρομή REST που αναφέρει το
 * `ecrm-sw.js` πρέπει να είναι σε αυτή τη λίστα. Αν κάποιος προσθέσει
 * `/contracts` ή `/customers` για «να ανοίγει offline», σταματά εδώ.
 *
 * @kanonas K9
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ServiceWorkerKeepsNoCustomerDataTest extends TestCase
{
    /** Ό,τι επιτρέπεται να αποθηκευτεί, με τον λόγο. */
    private const ALLOWED = [
        'providers' => 'κατάλογος παρόχων/προγραμμάτων για τη φόρμα (313)',
        'kb'        => 'Βάση Γνώσης και μαθήματα, οδηγοί του διαχειριστή (407)',
    ];

    public function testOnlyCatalogueAndGuidesAreKept(): void
    {
        $sw = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/ecrm-sw.js');

        self::assertNotSame('', $sw);

        // Μόνο ο κώδικας: τα σχόλια εξηγούν και αναφέρουν ελεύθερα διαδρομές.
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $sw);
        $code = (string) preg_replace('#^\s*//.*$#m', '', $code);

        preg_match_all('#ecrm/v1/([a-z][a-z0-9_-]*)#', $code, $ns);
        preg_match_all('#restUrl\([^,]+,\s*\'/([a-z][a-z0-9_-]*)#', $code, $built);

        $routes = array_values(array_unique(array_merge($ns[1], $built[1])));
        sort($routes);

        self::assertNotSame([], $routes, 'Δεν βρέθηκε καμία διαδρομή: άλλαξε η μορφή του ecrm-sw.js;');
        self::assertSame(
            [],
            array_values(array_diff($routes, array_keys(self::ALLOWED))),
            'Ο service worker αναφέρει διαδρομή που μπορεί να έχει στοιχεία πελατών.'
        );
    }

    public function testNothingButGetIsEverAnswered(): void
    {
        $sw = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/ecrm-sw.js');

        self::assertStringContainsString("if (req.method !== 'GET') { return; }", $sw);
    }
}
