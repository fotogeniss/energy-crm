<?php

/**
 * Κανένα unit test δεν φορτώνει legacy αρχείο χωρίς να ορίσει πρώτα τον φρουρό του.
 *
 * ## Τι έσπασε
 *
 * Κάθε αρχείο του legacy δέντρου ανοίγει με το πρότυπο του WordPress:
 *
 *     if ( ! defined( 'ABSPATH' ) ) {
 *         exit;
 *     }
 *
 * Σωστό εκεί που ζει — εμποδίζει την απευθείας κλήση από τον browser σε κώδικα
 * που χειρίζεται ΑΦΜ και σαρωμένες ταυτότητες. Η unit σουίτα όμως δεν σηκώνει
 * WordPress: το `tests/bootstrap.php` φορτώνει **μόνο** τον autoloader. Ένα
 * `require_once` προς τέτοιο αρχείο εκτελεί το `exit`.
 *
 * Και το `exit` χωρίς όρισμα είναι `exit(0)`. Δεν είναι κατάρρευση — είναι
 * **κανονικός τερματισμός**. Η διεργασία σβήνει στη μέση της σουίτας: καμία
 * γραμμή σύνοψης, κανένα `PHP Fatal error`, τίποτα στο stderr, κωδικός εξόδου
 * μηδέν. Ο composer προχωρά στην επόμενη εντολή και ο pre-commit hook περνά.
 *
 * Στις 22/08/2026 η σουίτα πέθαινε έτσι στο test **874 από τα 889**. Τα
 * δεκαπέντε από πίσω δεν εκτελούνταν καθόλου, ανάμεσά τους οι επτά του
 * `CustomerFieldsBackfillTest` και οι τρεις του `PersonalDataCoverageTest` —
 * δηλαδή οι φύλακες της κρυπτογράφησης ΑΦΜ και της κάλυψης προσωπικών
 * δεδομένων. Ολόκληρη η μέτρηση στο `docs/SUITE-874.html`.
 *
 * ## Γιατί δεν το έπιασε τίποτα
 *
 * Ο φύλακας που θα το έπιανε είναι η ίδια η σουίτα, και η σουίτα ήταν το θύμα.
 * Καμία δοκιμή συμπεριφοράς δεν μπορεί να ελέγξει κάτι που σκοτώνει τη
 * διεργασία πριν φτάσει η σειρά της — και ο pre-commit hook ρωτούσε τον κωδικό
 * εξόδου, που έλεγε **0**. Η άμυνα είναι δύο κομμάτια και χρειάζονται και τα
 * δύο: αυτό εδώ, που απαγορεύει το σχήμα, και το `tools/check-suite.php`, που
 * απαιτεί γραμμή σύνοψης αντί για κωδικό εξόδου.
 *
 * ## Τι επιτρέπεται
 *
 * Να φορτώσει ένα unit test legacy αρχείο, **αν ορίσει πρώτα την `ABSPATH`**.
 * Ο έλεγχος δεν απαγορεύει τη φόρτωση· απαγορεύει τη φόρτωση **στα τυφλά**.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NoUnitTestLoadsAGuardedFileTest extends TestCase
{
    /** `require`/`include` που δείχνει σε αρχείο του παλιού δέντρου. */
    private const LOAD = '/(?:require|include)(?:_once)?\s*[^;]{0,200}?'
        . "['\\\"]\\/(includes|admin|public|src)\\/([^'\\\"]+\\.php)['\\\"]/";

    /** Ο φρουρός του WordPress, όπως γράφεται πραγματικά στο legacy δέντρο. */
    private const GUARD = '/if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)\s*\{?\s*(?:exit|die)\b/';

    /** Ο ορισμός που κάνει τη φόρτωση ασφαλή. */
    private const DEFINES_ABSPATH = '/define\s*\(\s*[\'"]ABSPATH[\'"]/';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Κάθε unit test που φορτώνει αρχείο με φρουρό, με τα αρχεία που φορτώνει.
     *
     * @return array<string, list<string>>
     */
    private static function guardedLoads(): array
    {
        $root  = self::root();
        $found = [];

        /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $it */
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/tests/Unit', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all(self::LOAD, $source, $loads, PREG_SET_ORDER) < 1) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $guarded  = [];

            foreach ($loads as $load) {
                $target = $root . '/' . $load[1] . '/' . $load[2];

                if (! is_file($target)) {
                    continue;
                }

                if (preg_match(self::GUARD, (string) file_get_contents($target)) === 1) {
                    $guarded[] = $load[1] . '/' . $load[2];
                }
            }

            if ($guarded !== []) {
                sort($guarded);
                $found[$relative] = array_values(array_unique($guarded));
            }
        }

        ksort($found);

        return $found;
    }

    public function testEveryGuardedLoadDefinesAbspathFirst(): void
    {
        $root      = self::root();
        $offenders = [];

        foreach (self::guardedLoads() as $test => $targets) {
            $source = (string) file_get_contents($root . '/' . $test);

            if (preg_match(self::DEFINES_ABSPATH, $source) !== 1) {
                $offenders[$test] = $targets;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Ένα unit test φορτώνει legacy αρχείο που τερματίζει με `exit` όταν λείπει η\n"
            . "ABSPATH — και δεν την ορίζει.\n\n"
            . "Αυτό ΔΕΝ βγάζει σφάλμα. Το `exit` χωρίς όρισμα είναι `exit(0)`: η σουίτα\n"
            . "σβήνει στη μέση, χωρίς γραμμή σύνοψης, με κωδικό εξόδου 0. Ο composer\n"
            . "προχωρά και το commit περνά, ενώ κάθε test μετά από αυτό δεν έχει τρέξει.\n\n"
            . "Η διόρθωση είναι δύο γραμμές, πριν το require:\n\n"
            . "    if (! defined('ABSPATH')) {\n"
            . "        define('ABSPATH', self::root() . '/');\n"
            . "    }\n\n"
            . 'Δες docs/SUITE-874.html.'
        );
    }

    /**
     * Ο σαρωτής όντως βρίσκει φορτώσεις.
     *
     * Τρία regex πρέπει να ταιριάξουν στη σειρά για να πει κάτι το παραπάνω
     * test: η φόρτωση, ο φρουρός στο αρχείο-στόχο, και ο ορισμός στο test. Αν
     * σπάσει οποιοδήποτε από τα τρία, ο έλεγχος περνά πάντα και σιωπηλά —
     * δηλαδή γίνεται ακριβώς το είδος πράσινου που υπάρχει για να αποτρέψει.
     */
    public function testTheSweepIsActuallyLookingAtSomething(): void
    {
        self::assertArrayHasKey(
            'tests/Unit/Persistence/AfmIsNormalisedInOnePlaceTest.php',
            self::guardedLoads(),
            "Ο σαρωτής δεν βλέπει πια τη μία γνωστή φόρτωση legacy αρχείου.\n\n"
            . "Αν το AfmIsNormalisedInOnePlaceTest έπαψε να φορτώνει το\n"
            . "includes/class-ecrm-validate.php, σβήσε αυτό το test μαζί του. Αν όχι,\n"
            . 'ένα από τα regex έπαψε να ταιριάζει και ο φύλακας σαρώνει το κενό.'
        );
    }
}
