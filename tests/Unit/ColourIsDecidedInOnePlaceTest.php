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
 * ## Η λίστα άδειασε — και τι ΔΕΝ βλέπει ο κανόνας
 *
 * Στις 19/08/2026 μηδένισε: καμία τιμή χρώματος δεν γράφεται πια έξω από
 * ορισμό token, σε κανένα από τα δύο αρχεία. Από μετρητής χρέους έγινε
 * κατώφλι — ό,τι μπει από δω και πέρα είναι οπισθοδρόμηση.
 *
 * **Το τυφλό σημείο, γραμμένο ώστε να μην περάσει για κάλυψη:** ο κανόνας
 * ψάχνει **δεκαεξαδικά**. Δεν βλέπει `rgba()`, `hsl()` ούτε `color-mix()`.
 * Την ημέρα που άδειασε η λίστα, τα δύο CSS είχαν **36 τέτοιες τιμές μέσα σε
 * κανόνες** — 25 σε `box-shadow` (σκιές, άλλη συζήτηση) και **11 σε
 * `background` και `border`**, που είναι χρώμα με κάθε έννοια. Μηδέν
 * δεκαεξαδικά δεν σημαίνει μηδέν σκληροκωδικοποιημένο χρώμα.
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
     * **Κενή από 19/08/2026.** Ήταν 116 εμφανίσεις σε 61 τιμές το πρωί, 111 σε
     * 57 μόλις η σάρωση έμαθε να αγνοεί τα σχόλια, και μηδέν το βράδυ.
     *
     * Δεν είναι πια μετρητής χρέους — είναι το κατώφλι. Κάθε τιμή που θα
     * μπει εδώ από δω και πέρα είναι **οπισθοδρόμηση**, και πρέπει να
     * συνοδεύεται από τον λόγο της. Ο πρώτος έλεγχος από κάτω κοκκινίζει σε
     * κάθε νέο σκληροκωδικοποιημένο χρώμα· ο δεύτερος φροντίζει να μη μείνει
     * εδώ τίποτα που καθαρίστηκε.
     *
     * @var list<string>
     */
    private const LEFTOVERS = [];

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
