<?php

/**
 * Η κατάσταση σύμβασης γράφεται από ένα σημείο, και μόνο ένα.
 *
 * ## Τι έσπασε
 *
 * Ο `ContractLifecycle` ανοίγει με τη φράση *«Κάθε αλλαγή κατάστασης περνά από
 * εδώ, ώστε ο κύκλος ζωής να συμπεριφέρεται ίδια όπου κι αν πυροδοτηθεί»*.
 * Ήταν σχεδόν αλήθεια: οκτώ διαδρομές περνούσαν, μία όχι.
 *
 * Η `ECRM_Import::apply()` έγραφε `status` με σκέτο `$wpdb->update` και
 * καταχωρούσε το γεγονός με το χέρι. Έχανε και τα τέσσερα που κάνει η
 * `moveTo()`: τον γράφο μεταβάσεων, την ειδοποίηση στον συνεργάτη, **το SMS
 * στον πελάτη**, και το `ecrm_contract_status_changed` που ξεκινά τον
 * `AutoProcess`.
 *
 * Το τρίτο ήταν το χειρότερο, και όχι για τεχνικό λόγο: η κύρια ροή ενημέρωσης
 * καταστάσεων στην πράξη **είναι** το Excel του παρόχου. Δηλαδή η μία διαδρομή
 * που δεν ειδοποιούσε κανέναν ήταν ακριβώς αυτή που χρησιμοποιείται πιο πολύ,
 * ενώ η ΙΔΙΑ μετάβαση από το UI έστελνε κανονικά.
 *
 * ## Γιατί δεν το έπιασε τίποτα
 *
 * Καμία δοκιμή συμπεριφοράς δεν μπορούσε: η εισαγωγή δούλευε: οι καταστάσεις
 * άλλαζαν, η οθόνη έδειχνε σωστούς αριθμούς, το ιστορικό γέμιζε. Αυτό που δεν
 * συνέβαινε ήταν πράγματα που κανείς δεν περίμενε σε εκείνη τη διαδρομή, ακριβώς
 * επειδή δεν ήξερε ότι θα έπρεπε.
 *
 * Ένας δομικός έλεγχος είναι το μόνο είδος που πιάνει «λείπει κάτι που δεν
 * ζήτησε κανείς».
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Contract;

use PHPUnit\Framework\TestCase;

final class StatusIsWrittenInOnePlaceTest extends TestCase
{
    /**
     * Κάθε σημείο που επιτρέπεται να γράψει στήλη `status`, με τον λόγο.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'src/Persistence/ContractTransitions.php' =>
            'Η ΜΙΑ γραφή της κατάστασης σύμβασης. Φτάνει κανείς μόνο μέσω '
            . 'ContractLifecycle::moveTo(), που εφαρμόζει τον γράφο και ειδοποιεί.',

        'admin/class-ecrm-payouts.php' =>
            'Άλλος πίνακας: η κατάσταση της ΕΚΚΑΘΑΡΙΣΗΣ (pending/paid), όχι της '
            . 'σύμβασης. Δεν έχει γράφο μεταβάσεων και δεν ειδοποιεί κανέναν.',
    ];

    /** `$wpdb->update(...)` ή `insert(...)` που περνά κλειδί 'status'. */
    private const WRITE = "/\\\$wpdb->(?:update|insert)\\s*\\((.{0,400}?)\\)\\s*;/s";

    private const STATUS_KEY = "/['\\\"]status['\\\"]\\s*=>/";

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /**
     * @return list<string> Σχετικές διαδρομές, ταξινομημένες.
     */
    private static function filesThatWriteAStatus(): array
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

                $source = (string) file_get_contents($file->getPathname());

                if (preg_match_all(self::WRITE, $source, $calls) === false) {
                    continue;
                }

                foreach ($calls[1] as $arguments) {
                    if (preg_match(self::STATUS_KEY, $arguments) === 1) {
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

    public function testNothingElseWritesAContractStatus(): void
    {
        $allowed = array_keys(self::ALLOWED);
        sort($allowed);

        self::assertSame(
            $allowed,
            self::filesThatWriteAStatus(),
            "Το σύνολο των σημείων που γράφουν στήλη «status» άλλαξε.\n\n"
            . "Αν πρόσθεσες ένα: πέρασέ το από ContractLifecycle::moveTo(). Είναι το μόνο\n"
            . "μέρος που εφαρμόζει τον γράφο μεταβάσεων, καταγράφει το γεγονός, ειδοποιεί\n"
            . "τον συνεργάτη, στέλνει το SMS στον πελάτη και πυροδοτεί τον AutoProcess.\n"
            . "Μια γραφή που τα παρακάμπτει ΔΟΥΛΕΥΕΙ — απλώς δεν συμβαίνει τίποτα από τα\n"
            . "υπόλοιπα, και κανείς δεν το παρατηρεί για μήνες.\n\n"
            . 'Αν είναι άλλος πίνακας, πρόσθεσέ το στη ALLOWED με τον λόγο δίπλα.'
        );
    }

    /**
     * Ο σαρωτής όντως βρίσκει γραφές.
     *
     * Ένα regex που σταματά να ταιριάζει μετατρέπει το παραπάνω σε test που
     * περνάει πάντα — και εδώ θα σήμαινε «κανείς δεν γράφει status», που είναι
     * προφανώς ψέμα.
     */
    public function testTheSweepStillFindsTheOneTrueWrite(): void
    {
        self::assertContains(
            'src/Persistence/ContractTransitions.php',
            self::filesThatWriteAStatus(),
            'Ο σαρωτής δεν βλέπει πια ούτε την applyTransition() — το regex έπαψε να ταιριάζει.'
        );
    }
}
