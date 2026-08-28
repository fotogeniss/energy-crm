<?php

/**
 * Η βάση αγγίζεται από ένα layer, και μόνο ένα.
 *
 * ## Ο κανόνας
 *
 * `HANDOVER.md` §1.12, ρητή εντολή ιδιοκτήτη: το `src/Persistence/*` είναι το
 * **μοναδικό** σημείο πρόσβασης στη βάση· το `Domain` και το `Http` μιλάνε σε
 * repositories, ποτέ απευθείας σε `$wpdb`. Ο λόγος δεν είναι αισθητικός: όταν
 * το σύστημα φύγει από WordPress, το `Persistence` είναι το κομμάτι που
 * ξαναγράφεται (Eloquent αντί για `$wpdb`). Κάθε `$wpdb` που ζει έξω από εκεί
 * είναι μια ακόμη γραμμή που πρέπει να βρεθεί και να ξαναγραφτεί χειροκίνητα,
 * και το χειρότερο είδος της είναι αυτή που κανείς δεν ξέρει ότι υπάρχει.
 *
 * Ίδιος λόγος και για σήμερα, χωρίς καθόλου Laravel: `$wpdb` μέσα σε
 * controller σημαίνει query χωρίς `UserScope`, δηλαδή ερώτημα που δεν ξέρει
 * ποιανού τα δεδομένα διαβάζει. Η αρχή #1 της αρχιτεκτονικής υπάρχει ακριβώς
 * για να μην μπορεί να συμβεί αυτό κατά λάθος.
 *
 * ## Οι δύο εξαιρέσεις
 *
 * Είναι γραμμένες με τον λόγο τους. Ο έλεγχος δεν απαιτεί μηδέν — κλειδώνει
 * το «μέχρι εδώ», ώστε η τρίτη να κοκκινίσει τη σουίτα αντί να περάσει
 * απαρατήρητη.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Persistence;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class DatabaseAccessStaysInPersistenceTest extends TestCase
{
    /**
     * Αρχεία εκτός `src/Persistence/` που επιτρέπεται να αγγίζουν `$wpdb`.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'src/Infrastructure/ExtractionGate.php' =>
            'Μετράει ταυτόχρονες εξαγωγές με ατομικό counter. Δεν είναι δεδομένα '
            . 'της εφαρμογής αλλά κατάσταση χωρητικότητας του ίδιου του διακομιστή, '
            . 'και δεν έχει UserScope επειδή δεν αφορά κανέναν χρήστη.',

        'src/Infrastructure/HealthChecks.php' =>
            'Διαγνωστικά: ρωτάει τη βάση για τον εαυτό της (πίνακες, indexes, '
            . 'μηχανή αποθήκευσης). Repository για κάτι τέτοιο θα ήταν repository '
            . 'χωρίς οντότητα.',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return list<string> Σχετικές διαδρομές εκτός Persistence, ταξινομημένες.
     */
    private static function filesOutsidePersistenceUsingWpdb(): array
    {
        $root  = self::root();
        $found = [];

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $it */
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if (str_starts_with($relative, 'src/Persistence/')) {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), '$wpdb')) {
                $found[$relative] = true;
            }
        }

        $files = array_keys($found);
        sort($files);

        return $files;
    }

    public function testNothingOutsidePersistenceTouchesTheDatabase(): void
    {
        $allowed = array_keys(self::ALLOWED);
        sort($allowed);

        self::assertSame(
            $allowed,
            self::filesOutsidePersistenceUsingWpdb(),
            "Το σύνολο των αρχείων εκτός src/Persistence/ που χρησιμοποιούν \$wpdb άλλαξε.\n\n"
            . "Αν πρόσθεσες ένα: το ερώτημα ανήκει σε repository. Εκεί ζει το UserScope,\n"
            . "άρα εκεί είναι αδύνατο να ξεχαστεί ποιανού δεδομένα διαβάζεις — και εκεί\n"
            . "είναι το ΕΝΑ σημείο που ξαναγράφεται όταν φύγουμε από WordPress\n"
            . "(HANDOVER.md §1.12).\n\n"
            . 'Αν είναι πραγματικά υποδομή και όχι δεδομένα, πρόσθεσέ το στη ALLOWED με τον λόγο.'
        );
    }

    /** Ο σαρωτής όντως βρίσκει — αλλιώς ο έλεγχος περνάει πάντα και δεν σημαίνει τίποτα. */
    public function testTheSweepStillSeesTheRepositories(): void
    {
        $root = self::root();

        self::assertStringContainsString(
            '$wpdb',
            (string) file_get_contents($root . '/src/Persistence/ContractRepository.php'),
            'Ούτε το ContractRepository δεν φαίνεται να χρησιμοποιεί $wpdb — η ανάγνωση '
            . 'αρχείων έπαψε να δουλεύει και ο έλεγχος παραπάνω είναι κενός.'
        );
    }
}
