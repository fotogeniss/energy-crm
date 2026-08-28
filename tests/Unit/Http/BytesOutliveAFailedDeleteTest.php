<?php

/**
 * Όποιος σβήνει bytes σε διαδρομή διαγραφής, τα σβήνει ΤΕΛΕΥΤΑΙΑ.
 *
 * ## Το σφάλμα που γέννησε αυτόν τον φύλακα
 *
 * Στις 18/08/2026 (CHANGELOG (31), εύρημα 19) βρέθηκε ότι η **μαζική**
 * διαγραφή έσβηνε τα αρχεία πριν βεβαιωθεί ότι έφυγε η σύμβαση: μια
 * αποτυχημένη διαγραφή άφηνε τα σαρωμένα δελτία ταυτότητας οριστικά
 * σβησμένα, για σύμβαση που επέζησε. Διορθώθηκε εκεί με τρία βήματα —
 * στιγμιότυπο (`recordsForContracts`), διαγραφή, bytes μετά (`forgetBytes`).
 *
 * **Η μονή διαγραφή δεν διορθώθηκε ποτέ.** Έμεινε με την παλιά σειρά για δέκα
 * μέρες και εκατόν τριάντα εγγραφές, ενώ δίπλα της υπήρχε γραμμένο το μάθημα
 * και οι έτοιμες μέθοδοι. Δεν το έπιασε τίποτα: το `ContractDeleteBytesTest`
 * δοκιμάζει την **επιτυχή** διαγραφή, όπου και οι δύο σειρές δίνουν το ίδιο
 * αποτέλεσμα. Η διαφορά φαίνεται μόνο όταν αποτύχει η διαγραφή — δηλαδή
 * ακριβώς τότε που κανείς δεν κοιτάζει.
 *
 * ## Γιατί δομικός έλεγχος και όχι integration test
 *
 * Για να δοκιμαστεί η αποτυχία χρειάζεται να αποτύχει το `DELETE` της βάσης
 * εν μέσω αιτήματος, που δεν προκαλείται αξιόπιστα. Ο κανόνας όμως είναι
 * αναγνώσιμος από τον κώδικα: **αν ένα αρχείο σβήνει bytes συμβάσεων, πρέπει
 * να κρατά και στιγμιότυπο.** Αυτό ελέγχεται εδώ, φθηνά, σε κάθε νέο αρχείο.
 *
 * Ψάχνει tokens, όχι κείμενο: τα σχόλια αυτού του αρχείου αναφέρουν και τα
 * τρία ονόματα και δεν πρέπει να μετράνε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BytesOutliveAFailedDeleteTest extends TestCase
{
    /** Η καταστροφική: σβήνει γραμμές ΚΑΙ bytes μαζί. */
    private const PURGE = 'purgeForContracts';

    /** Το στιγμιότυπο που πρέπει να προηγηθεί. */
    private const SNAPSHOT = 'recordsForContracts';

    /** Το σβήσιμο των bytes, μετά. */
    private const AFTER = 'forgetBytes';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Κάθε controller — και του παλιού layer-first άξονα και των κάθετων φετών.
     *
     * Το δεύτερο μοτίβο δεν είναι προνοητικότητα: τέσσερις φύλακες γραμμένοι
     * με σταθερό `src/<Layer>/` κοκκίνισαν την πρώτη μέρα που εμφανίστηκε
     * κάθετη φέτα (CHANGELOG (161)). Ο κανόνας ισχύει για τον κώδικα που θα
     * γραφτεί, όχι μόνο γι' αυτόν που υπάρχει.
     *
     * @return list<array{string}>
     */
    public static function controllers(): array
    {
        $root = dirname(__DIR__, 3);
        $out  = [];

        foreach (['/src/Http/*.php', '/src/*/Http/*.php'] as $pattern) {
            foreach (glob($root . $pattern) ?: [] as $path) {
                $out[] = [str_replace('\\', '/', substr($path, strlen($root) + 1))];
            }
        }

        return $out;
    }

    #[DataProvider('controllers')]
    public function testAnyoneDeletingBytesTakesASnapshotFirst(string $relative): void
    {
        $calls = self::callsIn(self::root() . '/' . $relative);

        if (! in_array(self::PURGE, $calls, true)) {
            self::assertTrue(true, 'Δεν σβήνει bytes συμβάσεων — δεν το αφορά.');

            return;
        }

        $missing = array_values(array_diff([self::SNAPSHOT, self::AFTER], $calls));

        self::assertSame(
            [],
            $missing,
            "Το {$relative} καλεί " . self::PURGE . '() χωρίς ' . implode(' και ', $missing) . ".\n\n"
            . "Η σειρά «bytes πρώτα» σβήνει τα σαρωμένα δελτία ταυτότητας ΠΡΙΝ βεβαιωθεί\n"
            . "ότι έφυγε η σύμβαση. Αν αποτύχει η διαγραφή, τα έγγραφα έχουν χαθεί\n"
            . "οριστικά για σύμβαση που επέζησε — και το πρωτότυπο δεν υπήρξε ποτέ αλλού.\n\n"
            . "Τρία βήματα: \$doomed = recordsForContracts(\$ids); μετά η διαγραφή· και\n"
            . 'μόνο αν πέτυχε, purgeForContracts($ids) + forgetBytes($doomed).'
        );
    }

    /** Ο σαρωτής όντως βλέπει κώδικα, αλλιώς το παραπάνω περνά κοιτάζοντας το τίποτα. */
    public function testTheSweepIsActuallyLookingAtSomething(): void
    {
        $files = array_column(self::controllers(), 0);

        self::assertGreaterThan(20, count($files), 'Βρέθηκαν σχεδόν καθόλου controllers.');

        $deleters = [];

        foreach ($files as $relative) {
            if (in_array(self::PURGE, self::callsIn(self::root() . '/' . $relative), true)) {
                $deleters[] = $relative;
            }
        }

        // Οι δύο διαδρομές διαγραφής: μονή και μαζική. Αν γίνουν μία, ο
        // αριθμός πέφτει και αυτό είναι καλό νέο — αλλά θέλει να ειπωθεί εδώ.
        self::assertCount(
            2,
            $deleters,
            'Άλλαξε το πλήθος των διαδρομών που σβήνουν έγγραφα: ' . implode(', ', $deleters)
        );
    }

    /**
     * Τα ονόματα μεθόδων που καλούνται ως ΚΩΔΙΚΑΣ μέσα στο αρχείο.
     *
     * @return list<string>
     */
    private static function callsIn(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $names  = [];

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_STRING) {
                $names[] = $token[1];
            }
        }

        return $names;
    }
}
