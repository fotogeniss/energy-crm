<?php

/**
 * Ένα μέγεθος γράμματος επιτρέπεται να γραφτεί μόνο εκεί που ορίζεται token.
 *
 * Ο αδελφός έλεγχος για το χρώμα υπάρχει από τις 18/08 και η λίστα του άδειασε
 * την επόμενη μέρα. Για τη **γεωμετρία** δεν υπήρχε τίποτα — και αυτό δεν είναι
 * θεωρητικό κενό: η μέρα των πέντε παρτίδων UI (CHANGELOG 62–66) ήταν πέντε
 * παρτίδες ακριβώς επειδή καμία δοκιμή δεν έβλεπε μεγέθη. Το ίδιο μοτίβο βρέθηκε
 * τρεις φορές μέσα στην ίδια μέρα, με το σχόλιο «δεν ουρλιάζει καμία δοκιμή»
 * γραμμένο δίπλα του κάθε φορά.
 *
 * ## Τι μετρήθηκε πριν γραφτεί ο κανόνας
 *
 * Στις 20/08/2026, στα δύο φύλλα: **753 τιμές γεωμετρίας σε px χωρίς token**, από
 * τις οποίες **228 `font-size`** — σε **25 διακριτά μεγέθη**, εφαρμοσμένα στο
 * χέρι. Η εγγραφή (64) έγραφε «μία κλίμακα τυπογραφίας»· η κλίμακα δεν υπήρχε
 * πουθενά ως αντικείμενο. Ανάμεσα στα 25, έξι ζευγάρια απείχαν μισό ή ένα pixel
 * (13/13.5, 12/12.5, 11/11.5, 10/10.5, 16/17, 14/15) — διαφορές που κανείς δεν
 * επέλεξε ως σκαλιά· απλώς επιβίωσαν.
 *
 * ## Ο κανόνας
 *
 * Κάθε `font-size` σε κανόνα CSS πρέπει να είναι `var(--fs-…)` ή `var(--icon-…)`.
 * Ωμή τιμή επιτρέπεται **μόνο** σε γραμμή που ορίζει custom property. Ο κανόνας
 * δεν ξέρει σε ποιο αρχείο ζει η κλίμακα, οπότε δεν σπάει αν μετακινηθεί, και
 * ισχύει αυτόματα για κάθε CSS που θα προστεθεί αύριο.
 *
 * Τα εικονίδια είναι **χωριστό** λεξιλόγιο επίτηδες: το μέγεθος ενός ✕ δεν είναι
 * τυπογραφική απόφαση. Ο χωρισμός δεν ήταν αισθητικός — δύο από τα μεγέθη που
 * «δεν υπάρχουν στο UX kit» (20px, 30px) αποδείχθηκαν **μόνο** εικονίδια, και
 * έπαψαν να είναι εξαιρέσεις μόλις πήραν το δικό τους όνομα.
 *
 * ## Τι ΔΕΝ βλέπει ο κανόνας — γραμμένο ώστε να μην περάσει για κάλυψη
 *
 * 1. **Δεν κρίνει τιμές, μόνο ονόματα.** 18 σκαλιά κειμένου παραμένουν 18 σκαλιά·
 *    ο έλεγχος εγγυάται ότι είναι γραμμένα σε ένα σημείο, όχι ότι είναι σωστά.
 *    Το «πόσα σκαλιά τελικά» είναι απόφαση εμφάνισης — HANDOVER §6γ (13).
 * 2. **Δεν βλέπει `rem`, `em`, `%`, `clamp()`.** Την ημέρα που γράφτηκε δεν
 *    υπήρχε καμία τέτοια δήλωση στα δύο φύλλα, ούτε ένα `font:` shorthand
 *    (μετρημένο). Αν εμφανιστεί, θα περάσει αθόρυβα.
 * 3. **Δεν βλέπει inline styles από τη JavaScript**, ούτε τη δημόσια σελίδα
 *    υπογραφής: εκείνη έχει δικό της `<style>` μέσα σε
 *    `public/class-ecrm-tracking.php` και είναι `public/*.php`, όχι
 *    `public/assets/*.css`. Ο ίδιος τυφλός τόπος υπάρχει και στον χρωματικό
 *    έλεγχο — δες CHANGELOG (68), όπου βρέθηκαν εκεί επτά ωμά χρώματα.
 * 4. **Δεν βλέπει την υπόλοιπη γεωμετρία.** Μένουν **525** τιμές `padding`,
 *    `gap`, `border-radius`, `width`, `height` και `margin` χωρίς token.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypographyIsDecidedInOnePlaceTest extends TestCase
{
    /** Γραμμή που ΟΡΙΖΕΙ token — η μόνη θέση όπου επιτρέπεται ωμό μέγεθος. */
    private const DEFINITION = '/^\s*--[a-z0-9-]+\s*:/';

    /** Η τιμή μιας δήλωσης font-size, όπου κι αν βρίσκεται. */
    private const DECLARATION = '/(?<![-\w])font-size\s*:\s*([^;}]+)/';

    /**
     * Λέξεις-κλειδιά που ΔΕΝ είναι μεγέθη.
     *
     * Το `font-size: inherit` δεν αποφασίζει τίποτα — αναβάλλει την απόφαση σε
     * αυτόν που την πήρε ήδη πιο πάνω, που είναι ακριβώς ό,τι θέλει ο κανόνας.
     * Να ζητούσαμε token εκεί θα ήταν χειρότερο: θα κάρφωνε μέγεθος σε στοιχείο
     * που σήμερα ακολουθεί το γονιό του. Την ημέρα που γράφτηκε υπήρχε μία
     * τέτοια δήλωση, στο ecrm-form.css.
     *
     * @var list<string>
     */
    private const KEYWORDS = ['inherit', 'initial', 'unset', 'revert', 'revert-layer'];

    /** Ορισμός σκαλιού της κλίμακας. */
    private const STEP_DEFINITION = '/^\s*(--(?:fs|icon)-[0-9-]+)\s*:/';

    /** Το αρχείο που φορτώνεται ΠΑΝΤΑ — το ecrm-app.css εξαρτάται από αυτό. */
    private const SCALE_FILE = 'public/assets/ecrm-form.css';

    /**
     * Κάθε μέγεθος έχει όνομα.
     *
     * Το μήνυμα αποτυχίας λέει τι να κάνεις, γιατί η σωστή κίνηση δεν είναι
     * προφανής: αν το μέγεθος υπάρχει ήδη στην κλίμακα, χρησιμοποίησέ το· αν
     * όχι, το να προσθέσεις 26ο σκαλί είναι **απόφαση**, όχι λεπτομέρεια.
     */
    #[DataProvider('styleSheets')]
    public function testEveryFontSizeIsANamedStep(string $relative): void
    {
        $raw = [];

        foreach (explode("\n", $this->withoutComments($relative)) as $index => $line) {
            if (preg_match(self::DEFINITION, (string) $line) === 1) {
                continue;
            }

            if (preg_match_all(self::DECLARATION, (string) $line, $m) === 0) {
                continue;
            }

            foreach ($m[1] as $value) {
                $bare = strtolower(trim(str_replace('!important', '', $value)));

                if (in_array($bare, self::KEYWORDS, true)) {
                    continue;
                }

                if (strpos($value, 'var(--fs-') === false && strpos($value, 'var(--icon-') === false) {
                    $raw[] = $relative . ':' . ($index + 1) . ' → font-size: ' . trim($value);
                }
            }
        }

        self::assertSame(
            [],
            $raw,
            "Μέγεθος γράμματος χωρίς όνομα. Αν υπάρχει σκαλί με αυτή την τιμή, δείξε εκεί·\n"
            . "αν δεν υπάρχει, το νέο σκαλί μπαίνει στο :root του ecrm-form.css ΜΕ ΤΟΝ ΛΟΓΟ ΤΟΥ:\n"
            . implode("\n", $raw)
        );
    }

    /** Η κλίμακα ζει σε ένα αρχείο — αλλιώς δεν είναι κλίμακα, είναι δύο. */
    public function testTheScaleIsDefinedInOnePlace(): void
    {
        $files = [];

        foreach (array_column(self::styleSheets(), 0) as $relative) {
            if ($this->stepsDefinedIn($relative) !== []) {
                $files[] = $relative;
            }
        }

        self::assertSame([self::SCALE_FILE], $files, 'Η κλίμακα ορίζεται σε περισσότερα από ένα αρχεία.');
    }

    /**
     * Και κανένα σκαλί που δεν το χρησιμοποιεί κανείς.
     *
     * Χωρίς αυτό η κλίμακα γίνεται μουσείο: μεγέθη που έφυγαν από το CSS
     * μένουν στη λίστα και δείχνουν χρέος που δεν υπάρχει — ή, χειρότερα,
     * φαίνονται διαθέσιμα σε όποιον διαβάσει μόνο το μπλοκ.
     */
    public function testNoStepIsDefinedAndNeverUsed(): void
    {
        $used = '';

        foreach (array_column(self::styleSheets(), 0) as $relative) {
            $used .= $this->withoutComments($relative);
        }

        $dead = [];

        foreach ($this->stepsDefinedIn(self::SCALE_FILE) as $step) {
            if (substr_count($used, 'var(' . $step . ')') === 0) {
                $dead[] = $step;
            }
        }

        self::assertSame([], $dead, 'Ορισμένα και αχρησιμοποίητα: ' . implode(', ', $dead));
    }

    /**
     * Ότι ο έλεγχος κοιτάζει όντως κάτι.
     *
     * Χωρίς αυτό, μια αλλαγή διαδρομής θα έκανε τα παραπάνω πράσινα στο κενό —
     * που είναι χειρότερο από κόκκινο. Το κατώφλι είναι σκόπιμα χαμηλό (100 από
     * τις 228 της πρώτης μέρας): μετράει ότι υπάρχει σάρωση, όχι πόσο μεγάλο
     * είναι το CSS, ώστε να μη γίνει αριθμός που πρέπει να συντηρείται.
     */
    public function testTheSweepIsActuallyLookingAtSomething(): void
    {
        $sheets = self::styleSheets();

        self::assertGreaterThanOrEqual(2, count($sheets), 'Λείπουν CSS από τη σάρωση.');
        self::assertGreaterThanOrEqual(
            9,
            count($this->stepsDefinedIn(self::SCALE_FILE)),
            'Το μπλοκ της κλίμακας δεν βρέθηκε.'
        );

        $declarations = 0;

        foreach (array_column($sheets, 0) as $relative) {
            $declarations += preg_match_all(self::DECLARATION, $this->withoutComments($relative));
        }

        self::assertGreaterThan(100, $declarations, 'Η σάρωση δεν βρήκε δηλώσεις font-size — η διαδρομή άλλαξε.');
    }

    /**
     * Τα ονόματα των σκαλιών που ορίζονται σε ένα φύλλο.
     *
     * @return list<string>
     */
    private function stepsDefinedIn(string $relative): array
    {
        $steps = [];

        foreach (explode("\n", $this->withoutComments($relative)) as $line) {
            if (preg_match(self::STEP_DEFINITION, (string) $line, $m) === 1) {
                $steps[] = $m[1];
            }
        }

        return $steps;
    }

    /**
     * Το αρχείο χωρίς σχόλια, με τους αριθμούς γραμμής ανέπαφους.
     *
     * Ίδια τεχνική με τον χρωματικό έλεγχο, και για τον ίδιο λόγο: το μπλοκ της
     * κλίμακας εξηγεί τον εαυτό του σε είκοσι γραμμές σχολίου, και μια σάρωση
     * που τις μετρούσε θα κοκκίνιζε στην πρώτη αναδιατύπωση.
     */
    private function withoutComments(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;

        self::assertFileExists($path);

        $parts = preg_split('#(/\*.*?\*/)#s', (string) file_get_contents($path), -1, PREG_SPLIT_DELIM_CAPTURE);
        $clean = '';

        foreach ($parts === false ? [] : $parts as $index => $part) {
            $clean .= $index % 2 === 1 ? str_repeat("\n", substr_count($part, "\n")) : $part;
        }

        return $clean;
    }

    /** @return list<array{string}> */
    public static function styleSheets(): array
    {
        return [
            ['public/assets/ecrm-form.css'],
            ['public/assets/ecrm-app.css'],
        ];
    }
}
