<?php

/**
 * Το ΑΦΜ κόβεται σε ψηφία από μία συνάρτηση, όχι από όποιον το χρειάζεται.
 *
 * Το `afm_hash` είναι hash της αποθηκευμένης τιμής. Αν η αποθήκευση κρατά
 * «123 456 789» και η αναζήτηση ψάχνει «123456789», τα hash δεν ταιριάζουν ποτέ
 * και ο έλεγχος διπλοτύπων απαντά «κανένα». Δεν βγαίνει σφάλμα πουθενά, και ο
 * συνεργάτης καταχωρεί τον ίδιο πελάτη δεύτερη φορά.
 *
 * Στις 18/08/2026 υπήρχαν τέσσερις κανόνες στην ίδια διαδρομή: sanitize_text_field
 * στην αποθήκευση, preg_replace στο /contracts/duplicate, άλλο ένα στο
 * /lookup/afm, και σκέτο trim στο /customers/check.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;

final class AfmIsNormalisedInOnePlaceTest extends TestCase
{
    /**
     * Πού επιτρέπεται να ζει ένα pattern που κόβει μη-ψηφία, και γιατί.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'includes/class-ecrm-validate.php' =>
            'ECRM_Validate::digits() — ο ένας κανόνας για το ΑΦΜ.',

        'includes/class-ecrm-messaging.php' =>
            'normalize_phone(): τηλέφωνο, όχι ΑΦΜ. Άλλη έννοια, άλλοι κανόνες '
            . '(κωδικός χώρας, μήκος), και δεν μπαίνει σε blind index.',
    ];

    /** Μια συμβολοσειρά που μοιάζει με regex και περιέχει \D. */
    private const STRIPPER = '#^[\'"][^\'"]*/[^\'"]*\\\\D[^\'"]*/[a-zA-Z]*[\'"]$#';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return list<string>
     */
    private static function filesStrippingNonDigits(): array
    {
        $root  = self::root();
        $found = [];

        foreach (['public', 'includes', 'admin', 'src'] as $dir) {
            /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $it */
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                if (str_starts_with($relative, 'includes/lib/')) {
                    continue;
                }

                // Tokens και όχι grep: το «\D» υπάρχει σε κάθε namespace που
                // αρχίζει από D, και μια αναζήτηση κειμένου θα τα έπιανε όλα.
                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                    if (
                        is_array($token)
                        && $token[0] === T_CONSTANT_ENCAPSED_STRING
                        && preg_match(self::STRIPPER, $token[1]) === 1
                    ) {
                        $found[$relative] = true;
                        break;
                    }
                }
            }
        }

        $files = array_keys($found);
        sort($files);

        return $files;
    }

    public function testNobodyElseStripsNonDigits(): void
    {
        $allowed = array_keys(self::ALLOWED);
        sort($allowed);

        self::assertSame(
            $allowed,
            self::filesStrippingNonDigits(),
            "Άλλαξε το σύνολο των αρχείων που κόβουν μη-ψηφία μόνα τους.\n\n"
            . "Αν αφορά ΑΦΜ: ECRM_Validate::digits(). Δεύτερος κανόνας σημαίνει ότι η\n"
            . "αποθηκευμένη τιμή και η αναζητούμενη μπορεί να διαφέρουν, και τότε ο έλεγχος\n"
            . "διπλοτύπων λέει «κανένα» χωρίς να βγάλει σφάλμα.\n\n"
            . 'Αν αφορά κάτι άλλο, γράψ᾽ το στη ALLOWED με τον λόγο.'
        );
    }

    public function testDigitsKeepsOnlyDigits(): void
    {
        require_once self::root() . '/includes/class-ecrm-validate.php';

        self::assertSame('123456789', \ECRM_Validate::digits('123 456 789'));
        self::assertSame('123456789', \ECRM_Validate::digits('ΑΦΜ: 123.456.789'));
        self::assertSame('', \ECRM_Validate::digits('   '));
    }
}
