<?php

/**
 * Μία απάντηση στο «τι βλέπει ποιος», όχι δύο.
 *
 * ## Το bug που φυλάει
 *
 * Υπήρχαν δύο υλοποιήσεις, και διαφωνούσαν σε ένα ακριβώς σημείο:
 *
 * | | WordPressScopeResolver | ECRM_DB::visible_user_ids |
 * |---|---|---|
 * | διαχειριστής | όλοι | όλοι |
 * | **χωρίς `ecrm_manage_team`** | **μόνο ο εαυτός** | **όλο το υποδέντρο** |
 * | με `ecrm_manage_team` | υποδέντρο | υποδέντρο |
 *
 * Η δεύτερη δεν ρωτούσε καθόλου για το δικαίωμα. Και δεν την καλούσε κάτι
 * αθώο: `ECRM_Files::serve()` αποφασίζει ποιος κατεβάζει σαρωμένη ταυτότητα,
 * και `ECRM_Import::apply()` ποιων συμβάσεων αλλάζει την κατάσταση το Excel
 * του παρόχου — δηλαδή ποιος πληρώνεται.
 *
 * Με το σημερινό `Roles::matrix()` οι δύο συμπίπτουν, επειδή ο μόνος ρόλος με
 * `IMPORT_DATA` έχει και `MANAGE_TEAM`. Γι' αυτό ήταν **λανθάνον** και όχι
 * ενεργό — και γι' αυτό ακριβώς χρειάζεται φύλακα: το `Roles.php` υπόσχεται
 * «άλλαξε το matrix() και τίποτα άλλο δεν χρειάζεται να κουνηθεί», και μια
 * αφαίρεση του MANAGE_TEAM από τον Συνεργάτη θα άνοιγε την τρύπα σιωπηλά, σε
 * commit που θα φαινόταν καθαρή αλλαγή ρόλων.
 *
 * ## Πώς φυλάει
 *
 * Το `subtreeIds()` είναι η συντόμευση. Όποιος το καλέσει απευθείας χτίζει
 * λίστα ορατότητας **χωρίς** να περάσει από τον έλεγχο δικαιώματος — που είναι
 * ακριβώς πώς προέκυψε η δεύτερη υλοποίηση. Ένας καλών, ο resolver.
 *
 * Το `allUserIds()` σκόπιμα ΔΕΝ φυλάσσεται: δεν είναι πολιτική, είναι
 * επέκταση του «χωρίς περιορισμό» σε πραγματική λίστα, και γίνεται μόνο αφού
 * η πολιτική έχει ήδη αποφασίσει.
 *
 * @package EnergyCRM\Tests\Unit\Access
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VisibilityPolicyIsDecidedOnceTest extends TestCase
{
    /** Το μόνο αρχείο που επιτρέπεται να ζητήσει υποδέντρο. */
    private const RESOLVER = 'src/Access/WordPressScopeResolver.php';

    /** Εκεί που ορίζεται η μέθοδος· ο ορισμός δεν είναι κλήση. */
    private const OWNER = 'src/Persistence/NetworkRepository.php';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return list<array{string}>
     */
    public static function phpFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $out  = [];

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

                if (
                    str_starts_with($relative, 'includes/lib/')
                    || $relative === self::RESOLVER
                    || $relative === self::OWNER
                ) {
                    continue;
                }

                $out[] = [$relative];
            }
        }

        return $out;
    }

    #[DataProvider('phpFiles')]
    public function testOnlyTheResolverAsksForASubtree(string $relative): void
    {
        self::assertStringNotContainsString(
            '->subtreeIds(',
            (string) file_get_contents(self::root() . '/' . $relative),
            "Το {$relative} χτίζει λίστα ορατότητας από το δίκτυο απευθείας.\n\n"
            . "Έτσι ακριβώς προέκυψε η δεύτερη πολιτική που αγνοούσε το ecrm_manage_team:\n"
            . "ο ένας δρόμος ρωτούσε το δικαίωμα, ο άλλος όχι, και συμφωνούσαν μόνο\n"
            . "όσο ο ρόλος τύχαινε να έχει και τα δύο.\n\n"
            . 'Ζήτα Services::scopeResolver()->forUser($id) και ρώτα το UserScope που παίρνεις.'
        );
    }

    /** Ο σαρωτής όντως κοιτάζει, και ο resolver όντως ρωτά. */
    public function testTheSweepIsActuallyLookingAtSomething(): void
    {
        $files = array_column(self::phpFiles(), 0);

        self::assertGreaterThan(150, count($files), 'Βρέθηκαν σχεδόν καθόλου αρχεία — μετακινήθηκαν;');
        self::assertNotContains(self::RESOLVER, $files, 'Ο resolver δεν ελέγχεται από τον εαυτό του.');
        self::assertNotContains(self::OWNER, $files, 'Ο ορισμός της μεθόδου δεν είναι κλήση της.');
        self::assertFileExists(self::root() . '/' . self::RESOLVER);

        $resolver = (string) file_get_contents(self::root() . '/' . self::RESOLVER);

        self::assertStringContainsString(
            '->subtreeIds(',
            $resolver,
            'Ο resolver δεν ζητά πια υποδέντρο — τότε ο φύλακας από πάνω φυλάει κάτι που δεν συμβαίνει.'
        );

        self::assertStringContainsString(
            'ecrm_manage_team',
            $resolver,
            'Ο resolver έπαψε να ρωτά για το δικαίωμα. Αυτό ΗΤΑΝ ολόκληρη η διαφορά '
            . 'ανάμεσα στις δύο υλοποιήσεις.'
        );
    }
}
