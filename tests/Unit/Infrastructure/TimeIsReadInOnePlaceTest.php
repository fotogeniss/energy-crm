<?php

/**
 * Η ώρα διαβάζεται με έναν τρόπο, και γράφεται από ένα ρολόι.
 *
 * ## Γιατί υπάρχει
 *
 * Στις 22/08/2026 μετρήθηκε ότι το ίδιο δευτερόλεπτο έχει **δύο ονόματα** σε
 * αυτή την εγκατάσταση:
 *
 *     mysql = 13:48:33     ← DEFAULT CURRENT_TIMESTAMP, ON UPDATE, NOW()
 *     wp    = 10:48:33     ← current_time('mysql')
 *
 * Η αιτία ήταν μία ρύθμιση που έλειπε (ζώνη του site), αλλά το **σχήμα** του
 * λάθους κόστισε τρεις λανθασμένες διορθώσεις μέσα σε δύο μέρες — δύο δικές
 * μου και μία στην παρτίδα (77). Κάθε φορά κάποιος υπέθεσε ποιο ρολόι γράφει
 * μια στήλη αντί να το μετρήσει.
 *
 * Αυτό το αρχείο δεν ελέγχει συμπεριφορά· ελέγχει ότι **δεν ξαναγυρίζουν τα
 * δύο μοτίβα που το προκάλεσαν**. Στο ίδιο πνεύμα με τα
 * `TypographyIsDecidedInOnePlaceTest` και `ColourIsDecidedInOnePlaceTest`.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

final class TimeIsReadInOnePlaceTest extends TestCase
{
    /**
     * Το μοτίβο που πρόσθετε τη διαφορά ζώνης δεύτερη φορά.
     *
     * `new Date(s.replace(' ', 'T') + 'Z')` διαβάζει την αποθηκευμένη ώρα ως
     * UTC. Οι αποθηκευμένες είναι **τοπικές**, οπότε ο browser πρόσθετε ξανά
     * το offset: το «μόλις τώρα» για ό,τι έγινε τις τελευταίες τρεις ώρες, και
     * η **επόμενη μέρα** για ό,τι έγινε μετά τις 21:00.
     */
    public function testNoScriptReadsAStoredTimestampAsUtc(): void
    {
        $offenders = [];

        foreach ($this->scriptFiles() as $name => $path) {
            $source = (string) file_get_contents($path);

            // Μόνο σε κώδικα: τα σχόλια εξηγούν γιατί έφυγε και πρέπει να μείνουν.
            $code = preg_replace('#^\s*(//|\*|/\*).*$#m', '', $source) ?? $source;

            if (preg_match("/replace\(\s*' '\s*,\s*'T'\s*\)\s*\+\s*'Z'/", $code) === 1) {
                $offenders[] = $name;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Οι αποθηκευμένες ώρες είναι ΤΟΠΙΚΕΣ. Το + 'Z' τις διαβάζει ως UTC και προσθέτει τη "
            . "διαφορά δεύτερη φορά — δες ecrm-format.js και CHANGELOG (84)."
        );
    }

    /**
     * Ο σαρωτής κοιτάζει όντως κάτι.
     *
     * Χωρίς αυτό, ένα regex που σταμάτησε να ταιριάζει μετατρέπει το παραπάνω
     * σε έλεγχο που περνά πάντα — το ακριβότερο είδος πράσινου. Δανεισμένο από
     * το `FrontendEscapingTest`, που το χρειάζεται για τον ίδιο λόγο.
     */
    public function testTheSweepIsActuallyLookingAtSomething(): void
    {
        $parsers = 0;

        foreach ($this->scriptFiles() as $path) {
            $parsers += substr_count((string) file_get_contents($path), "replace(' ', 'T')");
            $parsers += substr_count((string) file_get_contents($path), "replace(' ','T')");
        }

        self::assertGreaterThan(
            0,
            $parsers,
            'Δεν βρέθηκε κανένας μετατροπέας ημερομηνίας — μετακινήθηκαν τα αρχεία;'
        );
    }

    /**
     * Καμία στήλη ώρας δεν γράφεται με ρητό UTC από PHP.
     *
     * Το `current_time('mysql', true)` γράφει UTC δίπλα σε στήλες που γράφει η
     * βάση σε ώρα site. Έτσι μια εκκαθάριση φαινόταν πληρωμένη **πριν**
     * δημιουργηθεί, και μια ειδοποίηση διαβασμένη πριν σταλεί. Η απάντηση και
     * στις δύο ήταν `NOW()` μέσα στο ερώτημα: ό,τι στέκεται δίπλα σε στήλη της
     * βάσης, το γράφει η βάση.
     */
    public function testNothingWritesAnExplicitUtcTimestampFromPhp(): void
    {
        $offenders = [];

        foreach ($this->phpFiles() as $name => $path) {
            $source = (string) file_get_contents($path);
            $code   = preg_replace('#^\s*(//|\*|/\*).*$#m', '', $source) ?? $source;

            if (preg_match("/current_time\(\s*'mysql'\s*,\s*true\s*\)/", $code) === 1) {
                $offenders[] = $name;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Ώρα δίπλα σε στήλη που γράφει η βάση γράφεται με NOW() στο ερώτημα, όχι με ρητό UTC "
            . 'από PHP — δες PayoutRepository::markPaid() και CHANGELOG (84).'
        );
    }

    /**
     * @return array<string, string>
     */
    private function scriptFiles(): array
    {
        return $this->filesIn(['public/assets'], ['js']) + $this->filesIn(['public'], ['php']);
    }

    /**
     * @return array<string, string>
     */
    private function phpFiles(): array
    {
        return $this->filesIn(['src', 'includes', 'admin', 'public'], ['php']);
    }

    /**
     * @param list<string> $directories
     * @param list<string> $extensions
     *
     * @return array<string, string>
     */
    private function filesIn(array $directories, array $extensions): array
    {
        $root  = dirname(__DIR__, 3);
        $found = [];

        foreach ($directories as $directory) {
            $path = $root . '/' . $directory;

            if (! is_dir($path)) {
                continue;
            }

            /** @var iterable<\SplFileInfo> $iterator */
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), $extensions, true)) {
                    continue;
                }

                // Τρίτων: δεν είναι δικός μας κώδικας και δεν αλλάζει από εδώ.
                if (str_contains($file->getPathname(), '/lib/')) {
                    continue;
                }

                $found[$file->getFilename()] = $file->getPathname();
            }
        }

        return $found;
    }
}
