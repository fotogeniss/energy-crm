<?php

/**
 * Ο χαρτης των ES modules δεν επιτρεπεται να διαφωνει με τα ιδια τα αρχεια.
 *
 * ## Το σφαλμα που δεν επιασε κανεις
 *
 * Το (243) προσθεσε την οθονη «Εγγραφα»: νεο `ecrm-view-documents.js`, και ενα
 * `import { loadDocuments } from '@energy-crm/view-documents'` στο
 * `ecrm-app.js`. Ο πινακας `MODULES` του `class-ecrm-shortcodes.php` -- που
 * ειναι το ΜΟΝΟ σημειο που μετατρεπει ενα τετοιο bare specifier σε πραγματικο
 * URL, μεσω `wp_register_script_module()` -- δεν το εμαθε ποτε.
 *
 * Ο browser δεν μπορει να λυσει ενα bare specifier χωρις import map, οποτε
 * απεριψε ΟΛΟΚΛΗΡΟ το `ecrm-app.js`:
 *
 *   Uncaught TypeError: Failed to resolve module specifier
 *   "@energy-crm/view-documents".
 *
 * Και επειδη το `ecrm-app.js` ειναι αυτο που γεμιζει καθε οθονη, ολη η
 * εφαρμογη εμενε στο «Φορτωση…» -- σε ΚΑΘΕ μενου, οχι μονο στα «Εγγραφα».
 * Ολη η σουιτα ηταν πρασινη: phpcs, phpstan, 1204 unit, 602 integration,
 * 31 wizard-smoke. Κανενας δεν κοιταζε αυτον τον χαρτη.
 *
 * ## Γιατι μονο εδω
 *
 * Η γνωση ειναι διπλογραμμενη εξ ορισμου: τα `import` ζουν στα .js, ο χαρτης
 * στην PHP, και ΤΙΠΟΤΑ δεν τα κραταει συγχρονισμενα. Ενα λαθος εδω δεν
 * σπαει μια οθονη -- σπαει την εφαρμογη, σιωπηλα, και φαινεται μονο σε
 * browser. Αυτη η δοκιμη ειναι η μονη αυτοματη αμυνα.
 *
 * Διαβαζεται ΚΕΙΜΕΝΟ, οχι φορτωμενη κλαση: το `class-ecrm-shortcodes.php`
 * θελει WordPress, και το `NoUnitTestLoadsAGuardedFileTest` απαγορευει ρητα
 * να το φορτωσει μια unit δοκιμη.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ModuleMapMatchesRealImportsTest extends TestCase
{
    private const SHORTCODES = '/public/class-ecrm-shortcodes.php';
    private const ASSETS     = '/public/assets';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function shortcodes(): string
    {
        return (string) file_get_contents(self::root() . self::SHORTCODES);
    }

    /**
     * Ο πινακας `id => αρχειο`.
     *
     * @return array<string, string>
     */
    private static function modules(): array
    {
        $php = self::shortcodes();

        $start = strpos($php, 'private const MODULES = [');
        self::assertNotFalse($start, 'Χαθηκε ο πινακας MODULES.');

        $end = strpos($php, '];', (int) $start);
        self::assertNotFalse($end, 'Ο πινακας MODULES δεν κλεινει.');

        preg_match_all(
            "/'(@energy-crm\/[\w-]+)'\s*=>\s*'([\w.-]+)'/",
            substr($php, (int) $start, (int) $end - (int) $start),
            $matches,
            PREG_SET_ORDER
        );

        $map = [];

        foreach ($matches as $m) {
            $map[$m[1]] = $m[2];
        }

        return $map;
    }

    /**
     * Ο πινακας `id => οσα δηλωνει οτι εισαγει`.
     *
     * @return array<string, list<string>>
     */
    private static function declaredDeps(): array
    {
        $php = self::shortcodes();

        $start = strpos($php, 'private const MODULE_DEPS = [');
        self::assertNotFalse($start, 'Χαθηκε ο πινακας MODULE_DEPS.');

        $end = strpos($php, "\n\t];", (int) $start);
        self::assertNotFalse($end, 'Ο πινακας MODULE_DEPS δεν κλεινει.');

        $block = substr($php, (int) $start, (int) $end - (int) $start);

        preg_match_all("/'(@energy-crm\/[\w-]+)'\s*=>\s*\[(.*?)\]/s", $block, $matches, PREG_SET_ORDER);

        $deps = [];

        foreach ($matches as $m) {
            preg_match_all("/'(@energy-crm\/[\w-]+)'/", $m[2], $inner);
            $deps[$m[1]] = $inner[1];
        }

        return $deps;
    }

    /**
     * Οσα ΠΡΑΓΜΑΤΙΚΑ εισαγει ενα αρχειο, με τη σειρα που τα γραφει.
     *
     * @return list<string>
     */
    private static function importsOf(string $file): array
    {
        $src = (string) file_get_contents(self::root() . self::ASSETS . '/' . $file);

        preg_match_all("/(?:from|import)\s+['\"](@energy-crm\/[\w-]+)['\"]/", $src, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * **Ο φυλακας που θα ειχε πιασει το σφαλμα.** Καθε bare specifier που
     * γραφεται σε οποιοδηποτε αρχειο πρεπει να υπαρχει στον χαρτη -- αλλιως ο
     * browser δεν εχει τροπο να τον λυσει και πεταει ολοκληρο το module.
     */
    public function testEveryImportedSpecifierIsRegistered(): void
    {
        $known   = array_keys(self::modules());
        $unknown = [];

        foreach (glob(self::root() . self::ASSETS . '/*.js') ?: [] as $path) {
            foreach (self::importsOf(basename($path)) as $specifier) {
                if (! in_array($specifier, $known, true)) {
                    $unknown[] = basename($path) . ' -> ' . $specifier;
                }
            }
        }

        self::assertSame(
            [],
            $unknown,
            "Bare specifier χωρις καταχωρηση στον MODULES -- ο browser θα απορριψει ολοκληρο\n"
            . "το module που τον γραφει, και μαζι καθε οθονη που εξαρταται απο αυτο.\n"
            . 'Προσθεσε το στον MODULES του public/class-ecrm-shortcodes.php.'
        );
    }

    /** Και καθε καταχωρημενο module πρεπει να δειχνει σε αρχειο που υπαρχει. */
    public function testEveryRegisteredModuleHasItsFile(): void
    {
        $missing = [];

        foreach (self::modules() as $id => $file) {
            if (! is_file(self::root() . self::ASSETS . '/' . $file)) {
                $missing[] = $id . ' -> ' . $file;
            }
        }

        self::assertSame([], $missing, 'Καταχωρημενο module χωρις αρχειο.');
    }

    /**
     * Οι δηλωμενες εξαρτησεις ειναι ο,τι ΠΡΑΓΜΑΤΙΚΑ εισαγει το αρχειο -- ουτε
     * λιγοτερες ουτε περισσοτερες.
     *
     * Λιγοτερες σημαινει οτι το `wp_register_script_module()` δεν ξερει ολο το
     * γραφημα, αρα ουτε το import map εγγυαται εκδοση για ολα -- ακριβως το
     * μπαγιατικο αρχειο που περιγραφει το σχολιο πανω απο τον πινακα.
     * Περισσοτερες σημαινει νεκρη δηλωση που ταιζει το map με κατι που κανεις
     * δεν χρειαζεται, και κρυβει ποιο ειναι το πραγματικο γραφημα.
     */
    public function testDeclaredDependenciesMatchTheFilesThemselves(): void
    {
        $declared = self::declaredDeps();
        $drift    = [];

        foreach (self::modules() as $id => $file) {
            $actual = self::importsOf($file);
            $said   = $declared[$id] ?? [];

            sort($actual);
            sort($said);

            if ($actual !== $said) {
                $drift[$id] = [
                    'λειπουν'     => array_values(array_diff($actual, $said)),
                    'περισσευουν' => array_values(array_diff($said, $actual)),
                ];
            }
        }

        self::assertSame([], $drift, 'MODULE_DEPS και πραγματικα import διαφωνουν.');
    }

    /** Και οτι η σαρωση κοιταζει πραγματικα κατι -- αλλιως περναει αδεια. */
    public function testTheSweepIsActuallyLookingAtSomething(): void
    {
        self::assertGreaterThan(20, count(self::modules()));
        self::assertContains('@energy-crm/view-documents', array_keys(self::modules()));
        self::assertContains('@energy-crm/view-documents', self::importsOf('ecrm-app.js'));
    }
}
