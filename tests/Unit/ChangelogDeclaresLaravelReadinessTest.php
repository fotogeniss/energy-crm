<?php

/**
 * Κάθε νέα εγγραφή CHANGELOG δηλώνει τι μεταφέρεται σε Laravel και τι όχι.
 *
 * ## Ο έλεγχος που γεννήθηκε από την ίδια του την αποτυχία
 *
 * Το `HANDOVER.md` §1.12 ορίζει διαδικασία ανά εργασία, και το τελευταίο της
 * βήμα είναι μια γραμμή «Laravel-ready;» στο τέλος κάθε εγγραφής: τι
 * μεταφέρεται αυτούσιο, τι θα ξαναγραφτεί.
 *
 * Μετρήθηκε στις 27/08/2026: η γραμμή υπάρχει σε **έντεκα** εγγραφές, τις
 * (98) έως (108), και **σε καμία** από την (109) ως την (158). Πενήντα
 * εγγραφές. Ο κανόνας δεν ανακλήθηκε ποτέ και κανείς δεν πήρε απόφαση να τον
 * σταματήσει — απλώς άλλαξε συνεδρία και έπαψε να θυμάται κανείς.
 *
 * Αυτό είναι το ακριβές σχήμα που ο ίδιος ο `StatusIsWrittenInOnePlaceTest`
 * περιγράφει: *«ένας δομικός έλεγχος είναι το μόνο είδος που πιάνει "λείπει
 * κάτι που δεν ζήτησε κανείς"»*. Ένα έγγραφο που περιγράφει τον κανόνα δεν
 * τον επιβάλλει. Μια κόκκινη σουίτα τον επιβάλλει.
 *
 * ## Γιατί κατώφλι και όχι όλες οι εγγραφές
 *
 * Οι (109)-(158) δεν διορθώνονται αναδρομικά. Το §1.11 λέει ρητά ότι το λάθος
 * μένει γραμμένο με σημείωση δίπλα του, δεν σβήνεται σιωπηλά — και μια
 * αναδρομική συμπλήρωση πενήντα εγγραφών θα ήταν ακριβώς τέτοιο σβήσιμο:
 * ψεύτικη ιστορία που λέει ότι ο κανόνας τηρούνταν πάντα. Το κατώφλι είναι η
 * (159), η πρώτη εγγραφή που τον ξαναπήρε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ChangelogDeclaresLaravelReadinessTest extends TestCase
{
    /**
     * Η πρώτη εγγραφή που ξαναπήρε τη γραμμή. Ό,τι είναι από εδώ και πάνω
     * ελέγχεται· ό,τι είναι από κάτω μένει ως έχει, ιστορία.
     */
    private const FIRST_ENTRY_UNDER_THE_RULE = 159;

    /** Ο τίτλος κάθε εγγραφής: `## 2026-08-27 (159)`. */
    private const ENTRY_HEADING = '/^## \d{4}-\d{2}-\d{2} \((\d+)\)\s*$/m';

    private static function changelog(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/docs/CHANGELOG.md');
    }

    /**
     * Κάθε εγγραφή με τον αριθμό της και το σώμα της.
     *
     * @return array<int, string> αριθμός εγγραφής => σώμα
     */
    private static function entries(): array
    {
        $body = self::changelog();

        if (preg_match_all(self::ENTRY_HEADING, $body, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $out   = [];
        $count = count($matches[0]);

        for ($i = 0; $i < $count; $i++) {
            $number = (int) $matches[1][$i][0];
            $start  = (int) $matches[0][$i][1];
            $end    = $i + 1 < $count ? (int) $matches[0][$i + 1][1] : strlen($body);

            $out[$number] = substr($body, $start, $end - $start);
        }

        return $out;
    }

    public function testEveryRecentEntryDeclaresItsLaravelReadiness(): void
    {
        $missing = [];

        foreach (self::entries() as $number => $body) {
            if ($number < self::FIRST_ENTRY_UNDER_THE_RULE) {
                continue;
            }

            if (! str_contains($body, 'Laravel-ready')) {
                $missing[] = $number;
            }
        }

        sort($missing);

        self::assertSame(
            [],
            $missing,
            "Εγγραφές CHANGELOG χωρίς γραμμή «Laravel-ready;»: "
            . implode(', ', array_map('strval', $missing)) . "\n\n"
            . "Το HANDOVER.md §1.12 τη ζητά σε κάθε εγγραφή: τι μεταφέρεται αυτούσιο σε\n"
            . "Laravel και τι θα ξαναγραφτεί. Δεν είναι τελετουργικό — είναι η στιγμή που\n"
            . "διαπιστώνεις ότι έβαλες λογική domain μέσα σε legacy κλάση, ΠΡΙΝ γίνει\n"
            . "commit.\n\n"
            . "Αυτός ο έλεγχος υπάρχει επειδή ο κανόνας ξεχάστηκε ήδη μία φορά, για\n"
            . 'πενήντα εγγραφές, χωρίς να το προσέξει κανείς.'
        );
    }

    /**
     * Ο αναλυτής όντως βρίσκει εγγραφές.
     *
     * Χωρίς αυτό, μια αλλαγή στη μορφή των τίτλων θα άδειαζε τον βρόχο
     * παραπάνω και ο έλεγχος θα περνούσε για πάντα λέγοντας το αντίθετο από
     * την αλήθεια.
     */
    public function testTheParserStillFindsEntries(): void
    {
        $entries = self::entries();

        self::assertGreaterThan(
            100,
            count($entries),
            'Ο αναλυτής βρήκε ελάχιστες εγγραφές — η μορφή των τίτλων του CHANGELOG '
            . 'άλλαξε και το regex έπαψε να ταιριάζει.'
        );

        self::assertArrayHasKey(
            self::FIRST_ENTRY_UNDER_THE_RULE,
            $entries,
            'Δεν βρέθηκε η εγγραφή (' . self::FIRST_ENTRY_UNDER_THE_RULE . '), '
            . 'που είναι το κατώφλι του κανόνα.'
        );
    }
}
