<?php

/**
 * Όποιος ζητά τη λίστα ενός scope, ρωτά πρώτα αν είναι διαχειριστής.
 *
 * ## Η παγίδα
 *
 * `UserScope::forAdministrator($id)` κρατά `[$id]` και σημαδεύει
 * `isAdministrator`. Άρα ένα scope διαχειριστή λέει ταυτόχρονα «περιλαμβάνω
 * τους πάντες» (`includes()`) και «η λίστα μου είναι ένα άτομο» (`userIds()`).
 *
 * Και τα δύο είναι σωστά: το `userIds()` απαντά **ποιος είναι ο δράστης**, όχι
 * **τι βλέπει**. Το `ScopeClause` δεν πέφτει ποτέ πάνω του, γιατί για
 * διαχειριστή δεν εκπέμπει καθόλου συνθήκη — και έτσι η παγίδα έμεινε αθέατη.
 *
 * Διαβασμένο ως «τι βλέπει», δίνει **άδεια εξαγωγή** και **κενές ειδοποιήσεις**.
 * Τρεις controllers το είχαν διαβάσει έτσι:
 *
 *   - `ContractDocumentsController::export()` με `scope=team`
 *   - `ContractsBulkController::export()`
 *   - `NotificationsController::index()` με `scope=team`
 *
 * Και δεν είναι θεωρητικά εύκολο να το αποφύγεις: γράφοντας τη διόρθωση του
 * ευρήματος 5 —σε άλλο αρχείο, την ίδια μέρα— παραλίγο να το ξαναγράψω.
 *
 * ## Γιατί δομικός και όχι συμπεριφορικός
 *
 * Η αποτυχία **δεν φωνάζει**. Δεν υπάρχει σφάλμα, δεν υπάρχει exception, δεν
 * υπάρχει λάθος γραμμή: υπάρχουν *λιγότερες* γραμμές. Ένα test συμπεριφοράς θα
 * έπρεπε να ξέρει εκ των προτέρων πόσες περίμενε — δηλαδή να ξέρει ήδη το bug.
 *
 * Ο κανόνας από την άλλη είναι απλός και ελέγξιμος: **αν ένα αρχείο ζητά
 * `->userIds()`, πρέπει να ρωτά και `isAdministrator()`**. Κάθε αποθετήριο το
 * κάνει. Οι τρεις controllers δεν το έκαναν, και τώρα δεν ζητούν καθόλου τη
 * λίστα — ρωτούν τον `ScopeResolver::visibleUserIds()`.
 *
 * Τα σχόλια δεν μετράνε: ο `token_get_all()` τα ξεχωρίζει, οπότε ένα σχόλιο που
 * *εξηγεί* γιατί δεν χρησιμοποιείται το `userIds()` δεν κοκκινίζει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdministratorScopeIsNotATeamTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Μόνο τα αρχεία που ΟΝΤΩΣ ζητούν τη λίστα.
     *
     * Ένα test ανά αρχείο του δέντρου θα ήταν 160 περιπτώσεις, οι 150 από τις
     * οποίες δεν θα είχαν τι να ελέγξουν και θα χρειάζονταν ψεύτικο assertion
     * για να μη μετρηθούν ως risky. Το φιλτράρισμα γίνεται εδώ.
     *
     * @return list<array{string}>
     */
    public static function callers(): array
    {
        $root = dirname(__DIR__, 3);

        return array_values(array_filter(
            self::phpFiles(),
            static fn (array $row): bool => self::inspect($root . '/' . $row[0])[0]
        ));
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

                if (! str_starts_with($relative, 'includes/lib/')) {
                    $out[] = [$relative];
                }
            }
        }

        return $out;
    }

    #[DataProvider('callers')]
    public function testAnyoneAskingForTheListAlsoAsksWhoIsAdministrator(string $relative): void
    {
        self::assertTrue(
            self::inspect(self::root() . '/' . $relative)[1],
            "Το {$relative} καλεί ->userIds() και δεν ρωτά πουθενά isAdministrator().\n\n"
            . "Για διαχειριστή το userIds() επιστρέφει ΜΟΝΟ τον ίδιο. Αν το διαβάζεις ως\n"
            . "«τι βλέπει», ο διαχειριστής παίρνει άδεια εξαγωγή ή κενή λίστα — και δεν\n"
            . "εμφανίζεται σφάλμα πουθενά, απλώς λιγότερα δεδομένα.\n\n"
            . "Αν θέλεις «τι βλέπει»: ScopeResolver::visibleUserIds(\$scope).\n"
            . 'Αν θέλεις «ποιος είναι ο δράστης»: ρώτα πρώτα isAdministrator() και πες γιατί.'
        );
    }

    /** Ο ανιχνευτής όντως πυροδοτεί, αλλιώς το παραπάνω περνάει πάντα. */
    public function testTheDetectorStillFiresOnAKnownCaller(): void
    {
        [$asksForList, $asksWhoIsAdmin] = self::inspect(self::root() . '/src/Persistence/ScopeClause.php');

        self::assertTrue($asksForList, 'Ο ScopeClause δεν ζητά πια λίστα — ο ανιχνευτής έπαψε να δουλεύει.');
        self::assertTrue($asksWhoIsAdmin, 'Ο ScopeClause δεν ρωτά πια isAdministrator() — αυτό θα ήταν το bug.');

        self::assertGreaterThan(150, count(self::phpFiles()), 'Βρέθηκαν σχεδόν καθόλου αρχεία.');
        self::assertGreaterThanOrEqual(
            6,
            count(self::callers()),
            'Σχεδόν κανείς δεν ζητά λίστα — ο ανιχνευτής κοιτάζει λάθος πράγμα.'
        );
    }

    /**
     * Ψάχνει ΚΩΔΙΚΑ, όχι κείμενο: τα σχόλια είναι δικά τους tokens.
     *
     * @return array{0: bool, 1: bool} [ζητά λίστα, ρωτά ποιος είναι διαχειριστής]
     */
    private static function inspect(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));

        $asksForList    = false;
        $asksWhoIsAdmin = false;
        $previous       = null;

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                $previous = null;

                continue;
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            if ($token[0] === T_STRING && $token[1] === 'isAdministrator') {
                $asksWhoIsAdmin = true;
            }

            // Μόνο ως ΚΛΗΣΗ σε αντικείμενο. Ο ορισμός μέσα στο UserScope είναι
            // `function userIds`, και δεν πρέπει να μετράει ως χρήση.
            if (
                $token[0] === T_STRING
                && $token[1] === 'userIds'
                && $previous !== null
                && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            ) {
                $asksForList = true;
            }

            $previous = $token;
        }

        return [$asksForList, $asksWhoIsAdmin];
    }
}
