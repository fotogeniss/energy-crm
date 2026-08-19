<?php

/**
 * Ένα χρώμα επιτρέπεται να γραφτεί μόνο εκεί που ορίζεται token.
 *
 * Το CRM έχει σύστημα δύο επιπέδων — πρωτογενή (`--n*`, `--p*`, `--st-*`) και
 * σημασιολογικά που δείχνουν σε αυτά (`--bg`, `--ink`, `--surface`) — και
 * **562 χρήσεις** `var(--…)` που το τηρούν. Παράλληλα όμως ζούσαν **182
 * τιμές χρώματος γραμμένες έξω από ορισμό token**, που δεν περνούσαν από
 * κανένα token και επομένως δεν άκουγαν καμία αλλαγή παλέτας. *Εκείνη η
 * μέτρηση έπιανε και τα σχόλια· δες τη λίστα παρακάτω.*
 *
 * Ο έλεγχος της 18/08 το είχε ονομάσει «τέσσερα ασύνδετα σύνολα χρωμάτων για
 * τις ίδιες δώδεκα καταστάσεις». Οι τιμές που απομένουν στη λίστα παρακάτω
 * είναι ακριβώς αυτό: υπολείμματα από τρεις παλιές παλέτες — slate navy,
 * κεχριμπαρένιο, και οικογένειες Tailwind — που κάνουν τη δουλειά των
 * `--st-*` δίπλα τους, χωρίς να το ξέρουν.
 *
 * ## Ο κανόνας, και γιατί δεν μετράει γραμμές
 *
 * Μια τιμή χρώματος επιτρέπεται **μόνο** σε γραμμή που ορίζει custom property
 * (`--κάτι: #χρώμα;`). Παντού αλλού απαγορεύεται. Ο κανόνας δεν ξέρει σε ποιο
 * αρχείο ή σε ποιες γραμμές ζει το μπλοκ των tokens, οπότε δεν σπάει όταν
 * μετακινηθεί — και ισχύει αυτόματα για κάθε CSS που θα προστεθεί αύριο.
 *
 * Ένα πράγμα εξαιρείται, και δεν είναι εξαίρεση: **τα σχόλια δεν είναι
 * κανόνες**. Τιμή γραμμένη μέσα σε σχόλιο CSS δεν βάφει pixel, οπότε σβήνεται
 * πριν τη σάρωση. Αλλιώς ο έλεγχος τιμωρεί ακριβώς ό,τι θέλουμε να γίνεται —
 * να γράφεται δίπλα στον κώδικα ποια τιμή αντικατέστησε ποια.
 *
 * ## Η λίστα ΜΟΝΟ μικραίνει
 *
 * Δεν είναι εξαίρεση, είναι **μετρητής χρέους**. Το δεύτερο test αρνείται
 * νεκρές εγγραφές: μόλις μια τιμή φύγει από το CSS, πρέπει να φύγει και από
 * εδώ, αλλιώς κοκκινίζει. Έτσι η λίστα δεν γίνεται ποτέ διακοσμητική, και το
 * πλήθος της είναι πάντα η αλήθεια για το πόσο απομένει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColourIsDecidedInOnePlaceTest extends TestCase
{
    /** Γραμμή που ΟΡΙΖΕΙ token — η μόνη θέση όπου επιτρέπεται ωμό χρώμα. */
    private const DEFINITION = '/^\s*--[a-z0-9-]+\s*:/';

    private const COLOUR = '/#[0-9a-fA-F]{3,8}\b/';

    /**
     * Ό,τι απέμεινε από τις παλιές παλέτες, στις 19/08/2026: **111
     * εμφανίσεις, 57 τιμές**. Κάθε μία που φεύγει από το CSS φεύγει κι από εδώ.
     *
     * Έγραφε «116 και 61» μέχρι που η σάρωση έμαθε να αγνοεί τα σχόλια. Οι
     * πέντε εμφανίσεις που έφυγαν ήταν προτάσεις που *ανέφεραν* ένα χρώμα,
     * και τέσσερις τιμές έφυγαν ολόκληρες — `#16c217`, `#e2e8f0`, `#475569`,
     * `#fcd34d` — επειδή δεν υπήρχαν πουθενά αλλού από ένα σχόλιο. Ο μετρητής
     * χρέους χρέωνε τεκμηρίωση, και το `#16c217` είναι το ίδιο το `--p400`
     * που ο κανόνας υποτίθεται ότι επιβάλλει.
     *
     * @var list<string>
     */
    private const LEFTOVERS = [
        '#0f172a', '#fef3c7', '#fff7ed', '#15803d', '#b91c1c', '#dc2626',
        '#ef4444', '#fef2f2', '#ea580c', '#047857', '#10b981', '#22c55e',
        '#8b5cf6', '#9a3412', '#c2410c', '#d97706', '#dcfce7', '#ecfdf5',
        '#f0a500', '#f0fdf4', '#fecaca', '#fee2e2', '#ffffff1a', '#ffffff26',
        '#0ea5e9', '#1d4ed8', '#2563eb', '#262521', '#34d399', '#38bdf8',
        '#3b82f6', '#3d3b36', '#4ade80', '#5b46e0', '#6d28d9', '#7360f2',
        '#7c2d12', '#86efac', '#9aa0a6', '#a4703a', '#a78bfa', '#a7f3d0',
        '#c9971f', '#dbeafe', '#e0a800', '#ede9fe', '#eef2ff', '#f87171',
        '#fafcff', '#fdba74', '#fde68a', '#fed7aa', '#ffedd5', '#fff5f5',
        '#fffdf5', '#ffffff0f', '#ffffff55',
    ];

    /** Κανένα νέο σκληροκωδικοποιημένο χρώμα. */
    #[DataProvider('styleSheets')]
    public function testEveryColourOutsideATokenDefinitionIsAKnownLeftover(string $relative): void
    {
        $unexpected = [];

        foreach ($this->coloursOutsideDefinitions($relative) as $line => $colours) {
            foreach ($colours as $colour) {
                if (! in_array(strtolower($colour), self::LEFTOVERS, true)) {
                    $unexpected[] = $relative . ':' . $line . ' → ' . $colour;
                }
            }
        }

        self::assertSame(
            [],
            $unexpected,
            "Νέο χρώμα εκτός token. Γράψ' το ως token στο μπλοκ .ecrm και δείξε εκεί:\n"
            . implode("\n", $unexpected)
        );
    }

    /**
     * Και καμία νεκρή εγγραφή, ώστε η λίστα να μη γίνει ποτέ διακόσμηση.
     */
    public function testTheLeftoverListHasNoEntriesThatNoLongerExist(): void
    {
        $present = [];

        foreach (array_column(self::styleSheets(), 0) as $relative) {
            foreach ($this->coloursOutsideDefinitions($relative) as $colours) {
                foreach ($colours as $colour) {
                    $present[strtolower($colour)] = true;
                }
            }
        }

        $dead = array_values(array_diff(self::LEFTOVERS, array_keys($present)));

        self::assertSame(
            [],
            $dead,
            'Καθαρίστηκαν και πρέπει να φύγουν από τη λίστα: ' . implode(', ', $dead)
        );
    }

    /**
     * Τα χρώματα ανά γραμμή, αγνοώντας τις γραμμές ορισμού.
     *
     * @return array<int, list<string>>
     */
    private function coloursOutsideDefinitions(string $relative): array
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;

        self::assertFileExists($path);

        $out = [];

        // Τα σχόλια δεν βάφουν pixel. Σβήνονται πριν τη σάρωση — αλλιώς μια
        // πρόταση που *αναφέρει* ένα χρώμα μετριέται ως χρέος, και η
        // αναδιατύπωσή της κοκκινίζει τη σουίτα χωρίς να έχει αλλάξει CSS.
        foreach (explode("\n", $this->withoutComments($path)) as $index => $line) {
            if (preg_match(self::DEFINITION, (string) $line) === 1) {
                continue;
            }

            if (preg_match_all(self::COLOUR, (string) $line, $m) > 0) {
                $out[$index + 1] = $m[0];
            }
        }

        return $out;
    }

    /**
     * Το αρχείο χωρίς σχόλια, με τους αριθμούς γραμμής ανέπαφους.
     *
     * Από κάθε σχόλιο κρατιούνται **μόνο οι αλλαγές γραμμής του**: το μήνυμα
     * αποτυχίας δείχνει «αρχείο:γραμμή», και μια σάρωση που μετακινεί τις
     * γραμμές θα έστελνε τον επόμενο σε λάθος σημείο του CSS.
     */
    private function withoutComments(string $path): string
    {
        $source = (string) file_get_contents($path);

        // Με DELIM_CAPTURE τα κομμάτια εναλλάσσονται: κείμενο, σχόλιο,
        // κείμενο… Τα μονά είναι τα σχόλια, και μένει από αυτά μόνο το πλήθος
        // των αλλαγών γραμμής τους.
        $parts = preg_split('#(/\*.*?\*/)#s', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
        $clean = '';

        foreach ($parts === false ? [$source] : $parts as $index => $part) {
            $clean .= $index % 2 === 1
                ? str_repeat("\n", substr_count($part, "\n"))
                : $part;
        }

        return $clean;
    }

    /**
     * Ότι ο έλεγχος κοιτάζει όντως κάτι.
     *
     * Χωρίς αυτό, ένα λάθος στη διαδρομή θα έκανε τη σάρωση να μη βρίσκει
     * αρχεία και όλα τα παραπάνω θα ήταν πράσινα στο κενό — που είναι
     * χειρότερο από κόκκινο.
     */
    public function testTheSweepIsActuallyLookingAtSomething(): void
    {
        $sheets = self::styleSheets();

        self::assertGreaterThanOrEqual(2, count($sheets), 'Λείπουν CSS από τη σάρωση.');

        // Και ότι διαβάζει πραγματικό περιεχόμενο, όχι άδειο αρχείο. Ο έλεγχος
        // δεν γίνεται πάνω στα υπολείμματα: εκείνα θα εξαφανιστούν, και τότε
        // ένας τέτοιος ισχυρισμός θα κοκκίνιζε ακριβώς τη μέρα που πετύχαμε.
        self::assertStringContainsString(
            '--n0:',
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/ecrm-form.css'),
            'Το μπλοκ των tokens δεν βρέθηκε — η διαδρομή άλλαξε.'
        );
    }

    /**
     * @return list<array{string}>
     */
    public static function styleSheets(): array
    {
        $files = glob(dirname(__DIR__, 2) . '/public/assets/*.css');

        return array_map(
            static fn (string $path): array => ['public/assets/' . basename($path)],
            $files === false ? [] : $files
        );
    }
}
