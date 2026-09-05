<?php

/**
 * Κάθε δρόμος από γραμμή προς πρόσωπο είναι δηλωμένος κάπου.
 *
 * ## Γιατί γράφτηκε, και γιατί όχι για bug
 *
 * Ο `PersonalDataTables` υπόσχεται: *«Ένας πίνακας που λείπει από εδώ είναι
 * πίνακας που το CRM ούτε αποκαλύπτει ούτε διαγράφει, και τίποτα δεν θα το
 * επισημάνει.»* Η υπόσχεση ήταν σωστή ως προειδοποίηση και **λάθος ως
 * περιγραφή**: ο χάρτης κρατά μόνο την ακμή της σύμβασης, ενώ η ακμή
 * `tasks.customer_id` ζει μέσα στους δύο καταναλωτές.
 *
 * Ο έλεγχος backend της 18/08/2026 διάβασε τον χάρτη, είδε το σχόλιο «only the
 * contract edge belongs in this map», και συμπέρανε ότι οι εργασίες που
 * κρέμονται μόνο από πελάτη δεν εξάγονται και δεν σβήνονται. **Λάθος.** Ο
 * `PersonalDataExporter::export()` τις συγχωνεύει και ο
 * `PersonalDataEraser::eraseTasks()` σβήνει με τα δύο κλειδιά — και το δεύτερο
 * τεκμηριώνει ακόμα και γιατί δεν διπλομετράει.
 *
 * Δεν υπήρχε λοιπόν bug. Υπήρχε **γνώση σε δύο σημεία και υπόσχεση σε τρίτο**,
 * που είναι αρκετό για να βγάλει λάθος συμπέρασμα κάποιος που διαβάζει
 * προσεκτικά. Αυτό το test κάνει την υπόσχεση αληθινή.
 *
 * ## Τι πραγματικά φυλάει
 *
 * Το ρεαλιστικό σενάριο δεν είναι να ξεχαστεί μια σημερινή ακμή· είναι να
 * προστεθεί **αύριο** πίνακας με `contract_id` ή `customer_id` — ένα
 * `ecrm_appointments`, ένα `ecrm_call_logs` — και να μη μπει πουθενά. Κανένα
 * test συμπεριφοράς δεν το πιάνει: το export και το erase θα συνεχίσουν να
 * περνάνε, απλώς για λιγότερα δεδομένα.
 *
 * Διαβάζει το ΣΧΗΜΑ, όχι μια λίστα — μια λίστα θα ήταν το τέταρτο αντίγραφο
 * της ίδιας γνώσης.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Persistence;

use EnergyCRM\Persistence\PersonalDataTables;
use EnergyCRM\Persistence\Tables;
use PHPUnit\Framework\TestCase;

final class PersonalDataCoverageTest extends TestCase
{
    /**
     * Ακμές που χειρίζονται οι καταναλωτές με το χέρι, με τον λόγο δίπλα.
     *
     * Δεν είναι λίστα «εξαιρέσεων»: είναι δήλωση ότι κάποιος τις σκέφτηκε.
     * Προσθήκη εδώ σημαίνει ότι ΚΑΙ οι δύο καταναλωτές τη χειρίζονται — και
     * αυτό το test το επαληθεύει διαβάζοντας τον κώδικά τους.
     *
     * @var array<string, string> "πίνακας.στήλη" => πού ζει ο χειρισμός
     */
    private const HANDLED_INLINE = [
        'tasks.customer_id' =>
            'Ο χάρτης είναι κλειδωμένος ανά πίνακα και το tasks έχει δύο ακμές. '
            . 'Exporter::export() συγχωνεύει, Eraser::eraseTasks() σβήνει και με τα δύο.',
        'customer_notes.customer_id' =>
            'Μοναδική ακμή του customer_notes -- κρέμεται μόνο από πελάτη, ποτέ από '
            . 'σύμβαση. Exporter::customerNotes() διαβάζει με Tables::CUSTOMER_NOTES, '
            . "Eraser::eraseCustomerNotes() σβήνει (DELETE, όχι redact -- η γραμμή "
            . 'ολόκληρη είναι ελεύθερο κείμενο).',
    ];

    /** Ο πίνακας που ΕΙΝΑΙ το υποκείμενο, όχι κάτι κρεμασμένο από αυτό. */
    private const ROOT = 'customers';

    /** Ρίζα της εξαγωγής: τα contracts αντλούνται ονομαστικά, όχι από τον χάρτη. */
    private const CONTRACTS = 'contracts';

    /**
     * Κάθε πίνακας του σχήματος και ποιες ξένες στήλες προς πρόσωπο κρατά.
     *
     * @return array<string, list<string>>
     */
    private static function schemaEdges(): array
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/class-ecrm-db.php');

        preg_match_all('/CREATE TABLE \{\$p\}(\w+) \((.*?)\n\t\t\)/s', $sql, $matches, PREG_SET_ORDER);

        $edges = [];

        foreach ($matches as $match) {
            $found = [];

            foreach (['customer_id', 'contract_id'] as $column) {
                if (preg_match('/^\s*' . $column . '\s/m', $match[2]) === 1) {
                    $found[] = $column;
                }
            }

            if ($found !== []) {
                $edges[$match[1]] = $found;
            }
        }

        return $edges;
    }

    public function testTheSchemaSweepFoundTheTablesWeKnowAbout(): void
    {
        $edges = self::schemaEdges();

        self::assertArrayHasKey(Tables::TASKS, $edges, 'Ο σαρωτής δεν διαβάζει πια το σχήμα.');
        self::assertSame(
            ['customer_id', 'contract_id'],
            $edges[Tables::TASKS],
            'Το tasks είναι ο πίνακας με τις δύο ακμές· αν έπαψε, ο χάρτης θέλει ξανασκέψιμο.'
        );
        self::assertGreaterThanOrEqual(6, count($edges), 'Βρέθηκαν σχεδόν καθόλου πίνακες.');
    }

    public function testEveryEdgeIsEitherMappedOrDeclaredByHand(): void
    {
        $mapped   = PersonalDataTables::linkedToContracts();
        $uncovered = [];

        foreach (self::schemaEdges() as $table => $columns) {
            foreach ($columns as $column) {
                // Τα contracts ΕΙΝΑΙ η ρίζα: ο exporter τα φέρνει ονομαστικά
                // και ο eraser ξεκινά από αυτά. Δεν κρέμονται από τίποτα.
                if ($table === self::CONTRACTS || $table === self::ROOT) {
                    continue;
                }

                if (($mapped[$table] ?? null) === $column) {
                    continue;
                }

                if (isset(self::HANDLED_INLINE[$table . '.' . $column])) {
                    continue;
                }

                $uncovered[] = $table . '.' . $column;
            }
        }

        self::assertSame(
            [],
            $uncovered,
            "Αυτές οι στήλες οδηγούν σε πρόσωπο και δεν τις ξέρει κανένας χάρτης:\n"
            . implode("\n", array_map(static fn (string $e): string => '  - ' . $e, $uncovered))
            . "\n\nΈνα αίτημα πρόσβασης δεν θα τις αποκαλύψει και ένα αίτημα διαγραφής\n"
            . "δεν θα τις σβήσει. Πρόσθεσέ τες στη PersonalDataTables, ή — αν ο πίνακας\n"
            . 'έχει δύο ακμές — στη HANDLED_INLINE, αφού τις χειριστούν ΚΑΙ οι δύο καταναλωτές.'
        );
    }

    /**
     * Μια δήλωση στη HANDLED_INLINE είναι ισχυρισμός για κώδικα αλλού.
     *
     * Χωρίς αυτό, η λίστα γίνεται τρόπος να σωπάσει το test από πάνω: γράφεις
     * τη γραμμή, το πράσινο επιστρέφει, και τα δεδομένα μένουν ακάλυπτα.
     */
    public function testWhatIsDeclaredInlineIsActuallyHandledByBothConsumers(): void
    {
        $root      = dirname(__DIR__, 3) . '/src/Persistence/';
        $exporter  = (string) file_get_contents($root . 'PersonalDataExporter.php');
        $eraser    = (string) file_get_contents($root . 'PersonalDataEraser.php');

        foreach (array_keys(self::HANDLED_INLINE) as $edge) {
            [$table, $column] = explode('.', $edge);

            $constant = 'Tables::' . strtoupper($table);

            foreach (['PersonalDataExporter' => $exporter, 'PersonalDataEraser' => $eraser] as $name => $source) {
                // Δύο αδύναμοι έλεγχοι, και το ξέρω: πιάνουν την ΑΦΑΙΡΕΣΗ του
                // χειρισμού, όχι την ορθότητά του. Αυτό είναι που χρειάζεται —
                // ότι ο χειρισμός είναι σωστός το λέει το PersonalDataErasureTest,
                // που δουλεύει με πραγματικές γραμμές. Εδώ φυλάσσεται μόνο ότι
                // η δήλωση παραπάνω δεν έγινε ψέμα.
                self::assertStringContainsString(
                    "'" . $column . "'",
                    $source,
                    "Η HANDLED_INLINE λέει ότι ο {$name} χειρίζεται το {$edge}, "
                    . 'και δεν αναφέρει καν τη στήλη. Ή ο χειρισμός έφυγε, ή η δήλωση ήταν ευχή.'
                );

                self::assertStringContainsString(
                    $constant,
                    $source,
                    "Ο {$name} δεν αναφέρει τον {$constant} — η δήλωση για το {$edge} "
                    . 'δείχνει σε χειρισμό που δεν υπάρχει.'
                );
            }
        }
    }
}
