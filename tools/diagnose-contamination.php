<?php

/**
 * How much damage the edit-path bug did before it was fixed.
 *
 * CHANGELOG 2026-08-16 (3) found it, (4) reproduced it on screen, (5) fixed
 * it. The fix stops new damage; it repairs nothing already written. This
 * script counts what is already written, so the decision about repair is made
 * against a number instead of a feeling.
 *
 * ## What it looks for
 *
 * Three signatures, in descending order of how certain they are:
 *
 *   1. Orphaned customers — a customer row no contract references. Counted
 *      first because it is easy to count, and immediately qualified in 1b,
 *      because it has a completely innocent explanation: deleting a draft
 *      contract leaves its customer behind by design (the foreign key is
 *      SET NULL, so commercial history survives a GDPR erasure). An orphan
 *      only means the bug if the contract that let go of it still exists.
 *      Measured on 2026-08-16 this signature was 31/31 explained by deletion
 *      — do not read it on its own.
 *
 *   2. Contracts with customer_id NULL — the same event seen from the other
 *      side. Less certain on its own: a draft saved with no customer data at
 *      all is also NULL, legitimately. Cross-referenced with (1) by time.
 *
 *   3. Audit events that emptied several fields at once — the full-row
 *      overwrite leaves a field_change message full of "→ ∅". One or two is
 *      an agent clearing a field; six in one save is the bug. Ranked, not
 *      judged: the threshold is printed so it can be argued with.
 *
 * ## What it never prints
 *
 * No ΑΦΜ, no ΑΔΤ, no name, no address, no audit message body — ids, codes,
 * timestamps and field *names* only. A diagnostic that dumps the personal data
 * it is counting into a terminal has become a second incident. Look the ids up
 * in the app.
 *
 *     php tools/diagnose-contamination.php
 *     php tools/diagnose-contamination.php /path/to/wordpress   (unusual layout)
 *
 * Always exits 0 — this reports, it does not gate anything. Read-only: it
 * writes nothing and changes nothing.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

// --- Boot WordPress --------------------------------------------------------

$root = $argv[1] ?? dirname(__DIR__, 4);
$load = rtrim((string) $root, '/\\') . '/wp-load.php';

if (! is_readable($load)) {
    fwrite(STDERR, "Δεν βρέθηκε το wp-load.php στο {$root}\n");
    fwrite(STDERR, "Δώσε τη διαδρομή του WordPress ως όρισμα.\n");
    exit(1);
}

require_once $load;

use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\Tables;

global $wpdb;

$contracts = Tables::name(Tables::CONTRACTS);
$customers = Tables::name(Tables::CUSTOMERS);
$events    = Tables::name(Tables::EVENTS);

/** Fields whose emptying matters — identity and commercial, not noise. */
const WATCHED_LABELS = [
    'ΑΦΜ', 'ΑΔΤ', 'ΔΟΥ', 'Όνομα', 'Επίθετο', 'Πατρώνυμο', 'Επωνυμία',
    'Ημ. γέννησης', 'Νομός', 'Πόλη', 'Οδός', 'Αριθμός', 'ΤΚ',
    'Τηλέφωνο', 'Κινητό', 'Email', 'Τύπος τιμής', 'Ενεργοποίηση',
    'Αριθμός παροχής', 'Μετρητής', 'Τιμολόγιο', 'Έναρξη', 'Λήξη',
];

/** Emptied-fields-in-one-save count above which a save looks like the bug. */
const MASS_EMPTY_THRESHOLD = 4;

function heading(string $text): void
{
    echo "\n", str_repeat('─', 74), "\n", $text, "\n", str_repeat('─', 74), "\n";
}

function line(string $text = ''): void
{
    echo $text, "\n";
}

echo "\n";
echo "Διάγνωση ζημιάς από το edit-path bug (CHANGELOG 2026-08-16 (3)/(4)/(5))\n";
echo "Βάση: ", DB_NAME, "   Πρόθεμα: ", $wpdb->prefix, "\n";
echo "Μόνο ανάγνωση. Δεν τυπώνονται προσωπικά δεδομένα — μόνο ids και πεδία.\n";

// --- Baseline --------------------------------------------------------------

$totalContracts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$contracts}");
$totalCustomers = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$customers}");

heading('0. Μέγεθος');
line("Συμβάσεις: {$totalContracts}");
line("Πελάτες:   {$totalCustomers}");

// --- 1. Orphaned customers -------------------------------------------------

/** @var list<array<string, mixed>> $orphans */
$orphans = $wpdb->get_results(
    "SELECT cu.id, cu.created_at
       FROM {$customers} cu
       LEFT JOIN {$contracts} c ON c.customer_id = cu.id
      WHERE c.id IS NULL
      ORDER BY cu.id",
    ARRAY_A
);

heading('1. Ορφανοί πελάτες — καμία σύμβαση δεν τους δείχνει');
line('ΜΗΝ διαβάσεις αυτό το νούμερο μόνο του — η ενότητα 1β το κρίνει. Ένας');
line('ορφανός πελάτης σημαίνει είτε ότι μια σύμβαση έχασε τον πελάτη της (bug)');
line('είτε ότι η σύμβαση διαγράφηκε και τον άφησε πίσω (κανονικό: το foreign key');
line('είναι SET NULL, ώστε μια διαγραφή για GDPR να μη σβήνει εμπορικό ιστορικό).');
line();

if ($orphans === []) {
    line('✓ Κανένας. Καμία σύμβαση δεν έχασε τον πελάτη της.');
} else {
    line('⚠ ' . count($orphans) . ' ορφανοί πελάτες. Ids (δες τους στην εφαρμογή):');
    line();

    foreach ($orphans as $row) {
        line(sprintf('    customer #%-6s  δημιουργήθηκε %s', $row['id'], $row['created_at']));
    }
}

// --- 1b. Or were they orphaned by deletion, which is innocent? --------------

$contractsMaxId = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$contracts}");
$customersMaxId = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$customers}");

$contractsGone = max(0, $contractsMaxId - $totalContracts);
$customersGone = max(0, $customersMaxId - $totalCustomers);

$ghostEvents = (int) $wpdb->get_var(
    "SELECT COUNT(DISTINCT e.contract_id)
       FROM {$events} e
       LEFT JOIN {$contracts} c ON c.id = e.contract_id
      WHERE c.id IS NULL"
);

heading('1β. Ή μήπως ορφάνεψαν από διαγραφή, που είναι αθώο;');
line('Η ενότητα 1 μόνη της δεν ξεχωρίζει δύο πολύ διαφορετικά πράγματα: σύμβαση');
line('που ΕΧΑΣΕ τον πελάτη της (bug) και σύμβαση που ΔΙΑΓΡΑΦΗΚΕ αφήνοντάς τον');
line('πίσω (κανονική λειτουργία — ο πελάτης δεν σβήνει μαζί). Τα κενά στα id και');
line('τα events που δείχνουν σε ανύπαρκτες συμβάσεις ξεχωρίζουν τα δύο.');
line();
line(sprintf('    Συμβάσεις:  %d γραμμές, μεγαλύτερο id %d  →  %d id λείπουν', $totalContracts, $contractsMaxId, $contractsGone));
line(sprintf('    Πελάτες:    %d γραμμές, μεγαλύτερο id %d  →  %d id λείπουν', $totalCustomers, $customersMaxId, $customersGone));
line(sprintf('    Events που δείχνουν σε ανύπαρκτη σύμβαση: %d', $ghostEvents));
line();
line('    (Το τελευταίο είναι ΠΑΝΤΑ 0 και δεν αποδεικνύει τίποτα: το foreign key');
line('     events.contract_id είναι ON DELETE CASCADE — AddForeignKeys.php:58.');
line('     Τυπώνεται μόνο ως έλεγχος ότι το CASCADE όντως δουλεύει.)');
line();

$orphanCount   = count($orphans);
$deletionFits  = $orphanCount > 0 && $contractsGone >= (int) floor($orphanCount * 0.7);

if ($orphanCount === 0) {
    line('Δεν υπάρχουν ορφανοί — τίποτα να εξηγηθεί.');
} elseif ($deletionFits) {
    line(sprintf(
        '→ Η ΔΙΑΓΡΑΦΗ εξηγεί τους ορφανούς: λείπουν %d id συμβάσεων και υπάρχουν',
        $contractsGone
    ));
    line(sprintf('  %d ορφανοί πελάτες. Οι αριθμοί ταιριάζουν. Αυτό ΔΕΝ είναι το bug —', $orphanCount));
    line('  είναι το αναμενόμενο αποτέλεσμα του να σβήνεις πρόχειρες συμβάσεις.');
    line();
    line('  Παρενέργεια που αξίζει χωριστή απόφαση: ο πελάτης, με ΑΦΜ και ΑΔΤ');
    line('  μέσα, επιβιώνει της σύμβασης που τον δημιούργησε και δεν φαίνεται');
    line('  πουθενά στην εφαρμογή. Αυτό είναι θέμα διατήρησης δεδομένων (GDPR),');
    line('  όχι το cross-contamination — ξεχωριστό εύρημα, ξεχωριστή συζήτηση.');
} else {
    line(sprintf(
        '→ Η διαγραφή ΔΕΝ τους εξηγεί: λείπουν μόνο %d id συμβάσεων για %d ορφανούς.',
        $contractsGone,
        $orphanCount
    ));
    line('  Απομένει η διαδρομή του bug που φτιάχνει ΔΙΠΛΟΕΓΓΡΑΦΗ: σε edit όπου το');
    line('  customer_id δεν έφτανε στον server, η resolveCustomer() επέστρεφε 0, τα');
    line('  στοιχεία πελάτη ήταν γεμάτα, και η save() δημιουργούσε ΝΕΟ πελάτη —');
    line('  αφήνοντας τον προηγούμενο ορφανό. Δες την ενότητα 1γ.');
}

// --- 1c. Duplicate customers — the signature of the "new customer per edit" -

$indexColumn = CustomerFields::INDEX_COLUMN;
$hasIndex    = (bool) $wpdb->get_var(
    $wpdb->prepare("SHOW COLUMNS FROM {$customers} LIKE %s", $indexColumn)
);

heading('1γ. Διπλοεγγραφές πελατών — ίδιο ΑΦΜ σε πολλές γραμμές');
line('Αν κάθε edit έφτιαχνε νέο πελάτη, το ίδιο ΑΦΜ θα εμφανιζόταν σε πολλές');
line('γραμμές. Συγκρίνεται το afm_hash, όχι το ΑΦΜ: το hash υπάρχει και όταν η');
line('στήλη είναι κρυπτογραφημένη, όπου δύο ίδια ΑΦΜ δεν μοιάζουν καθόλου.');
line();

if (! $hasIndex) {
    line('    (η στήλη ' . $indexColumn . ' δεν υπάρχει — δεν μπορεί να ελεγχθεί)');
} else {
    /** @var list<array<string, mixed>> $dupes */
    $dupes = $wpdb->get_results(
        "SELECT {$indexColumn} AS h, COUNT(*) AS n
           FROM {$customers}
          WHERE {$indexColumn} IS NOT NULL AND {$indexColumn} <> ''
          GROUP BY {$indexColumn}
         HAVING n > 1
          ORDER BY n DESC",
        ARRAY_A
    );

    if ($dupes === []) {
        line('✓ Κανένα ΑΦΜ δεν εμφανίζεται σε πάνω από μία γραμμή πελάτη.');
        line('  Η διαδρομή της διπλοεγγραφής δεν άφησε ίχνος.');
    } else {
        $extra = 0;

        foreach ($dupes as $row) {
            $extra += ((int) $row['n']) - 1;
        }

        line(sprintf(
            '⚠ %d ΑΦΜ εμφανίζονται πολλαπλά — %d περιττές γραμμές πελάτη συνολικά:',
            count($dupes),
            $extra
        ));
        line();

        foreach ($dupes as $i => $row) {
            // The hash is a keyed digest, not the ΑΦΜ, but it is still a stable
            // identifier for one person — only its position is printed.
            line(sprintf('    ομάδα %-3d %d γραμμές με το ίδιο ΑΦΜ', $i + 1, (int) $row['n']));
        }
    }
}

// --- 2. Contracts that lost their customer ---------------------------------

/** @var list<array<string, mixed>> $lost */
$lost = $wpdb->get_results(
    "SELECT id, code, status, created_at, updated_at,
            (updated_at > created_at) AS was_edited
       FROM {$contracts}
      WHERE customer_id IS NULL
      ORDER BY id",
    ARRAY_A
);

$lostEdited = array_values(array_filter($lost, static fn (array $r): bool => (int) $r['was_edited'] === 1));

heading('2. Συμβάσεις χωρίς πελάτη (customer_id IS NULL)');
line('Το ίδιο συμβάν από την άλλη πλευρά. Μόνη της η υπογραφή δεν αρκεί: ένα');
line('προσχέδιο που αποθηκεύτηκε χωρίς κανένα στοιχείο πελάτη είναι επίσης NULL,');
line('νόμιμα. Οι επεξεργασμένες (updated_at > created_at) είναι οι ύποπτες.');
line();

if ($lost === []) {
    line('✓ Καμία.');
} else {
    line(sprintf(
        '⚠ %d συνολικά, εκ των οποίων %d έχουν επεξεργαστεί μετά τη δημιουργία:',
        count($lost),
        count($lostEdited)
    ));
    line();

    foreach ($lost as $row) {
        line(sprintf(
            '    σύμβαση #%-6s %-14s %-10s δημ. %s  ενημ. %s  %s',
            $row['id'],
            (string) ($row['code'] ?? '—'),
            (string) $row['status'],
            $row['created_at'],
            $row['updated_at'],
            ((int) $row['was_edited'] === 1) ? '← ΥΠΟΠΤΗ' : ''
        ));
    }
}

// --- 3. Saves that emptied several fields at once ---------------------------

$needle = '→ ∅';

/** @var list<array<string, mixed>> $massEmpty */
$massEmpty = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT e.id, e.contract_id, e.user_id, e.created_at,
                (CHAR_LENGTH(e.message) - CHAR_LENGTH(REPLACE(e.message, %s, '')))
                    / CHAR_LENGTH(%s) AS emptied
           FROM {$events} e
          WHERE e.type = 'field_change'
            AND e.message LIKE %s
         HAVING emptied >= %d
          ORDER BY emptied DESC, e.created_at DESC",
        $needle,
        $needle,
        '%' . $wpdb->esc_like($needle) . '%',
        MASS_EMPTY_THRESHOLD
    ),
    ARRAY_A
);

heading('3. Αποθηκεύσεις που άδειασαν πολλά πεδία μαζί');
line('Η πλήρης αντικατάσταση αφήνει ίχνος: ένα field_change γεμάτο «→ ∅». Ένα ή');
line('δύο είναι συνεργάτης που καθάρισε ένα πεδίο· ' . MASS_EMPTY_THRESHOLD . '+ σε μία αποθήκευση είναι το');
line('bug. Το κατώφλι τυπώνεται για να μπορεί να αμφισβητηθεί.');
line();

if ($massEmpty === []) {
    line('✓ Καμία αποθήκευση δεν άδειασε ' . MASS_EMPTY_THRESHOLD . ' ή περισσότερα πεδία ταυτόχρονα.');
} else {
    line('⚠ ' . count($massEmpty) . ' αποθηκεύσεις:');
    line();

    foreach ($massEmpty as $row) {
        line(sprintf(
            '    σύμβαση #%-6s  %d πεδία άδειασαν  %s  (χρήστης %s, event %s)',
            $row['contract_id'],
            (int) $row['emptied'],
            $row['created_at'],
            (string) ($row['user_id'] ?? '—'),
            $row['id']
        ));
    }
}

// --- 3b. Which fields, by name, across those saves --------------------------

if ($massEmpty !== []) {
    $ids     = array_map(static fn (array $r): int => (int) $r['id'], $massEmpty);
    $inList  = implode(',', array_fill(0, count($ids), '%d'));

    /** @var list<string> $messages */
    $messages = $wpdb->get_col(
        $wpdb->prepare("SELECT message FROM {$events} WHERE id IN ({$inList})", $ids)
    );

    $tally = [];

    foreach ($messages as $message) {
        foreach (WATCHED_LABELS as $label) {
            // "ΑΔΤ: κάτι → ∅" — the label, then anything, then the empty mark,
            // stopping at the separator so one field cannot claim another's.
            if (preg_match('/(?:^|·\s)' . preg_quote($label, '/') . ':\s[^·]*→ ∅/u', $message) === 1) {
                $tally[$label] = ($tally[$label] ?? 0) + 1;
            }
        }
    }

    arsort($tally);

    heading('3β. Ποια πεδία άδειασαν, με το όνομά τους');
    line('Πόσες φορές το καθένα, στις αποθηκεύσεις της ενότητας 3. Ονόματα πεδίων,');
    line('όχι τιμές.');
    line();

    if ($tally === []) {
        line('    (κανένα από τα παρακολουθούμενα πεδία — μόνο σημειώσεις/λοιπά)');
    } else {
        foreach ($tally as $label => $count) {
            line(sprintf('    %-20s %d', $label, $count));
        }
    }
}

// --- Verdict ---------------------------------------------------------------

// Orphans explained by deletion are not suspects — subtracting them is the
// whole point of 1β, and a summary that ignored it would contradict the
// section above it.
$orphanSuspects = $deletionFits ? 0 : count($orphans);
$suspects       = $orphanSuspects + count($lostEdited) + count($massEmpty);

heading('Σύνοψη');

if ($deletionFits) {
    line(sprintf(
        'Οι %d ορφανοί πελάτες εξηγούνται από διαγραφές συμβάσεων (1β) και δεν',
        count($orphans)
    ));
    line('μετρώνται ως ζημιά.');
    line();
}

if ($suspects === 0) {
    line('Καμία από τις τρεις υπογραφές δεν βρέθηκε.');
    line();
    line('Αυτό ΔΕΝ αποδεικνύει ότι δεν χάθηκε τίποτα: η cross-contamination από τη');
    line('φόρμα (τιμή Α → σύμβαση Β) αντικαθιστά τιμή με τιμή, δεν αδειάζει πεδίο,');
    line('και μοιάζει στο audit με κανονική επεξεργασία. Σημαίνει ότι η');
    line('καταστροφική μισή του bug — το άδειασμα — δεν άφησε ίχνος εδώ.');
} else {
    line("Ύποπτες εγγραφές συνολικά: {$suspects}");
    line();
    line('  ' . $orphanSuspects . ' ορφανοί πελάτες            (υπογραφή 1 — μόνο όσοι δεν εξηγούνται από 1β)');
    line('  ' . count($lostEdited) . ' συμβάσεις χωρίς πελάτη     (υπογραφή 2 — ίδιο συμβάν, άλλη όψη)');
    line('  ' . count($massEmpty) . ' μαζικά αδειάσματα          (υπογραφή 3 — πολύ πιθανή)');
    line();
    line('Επόμενο: οι ορφανοί πελάτες και οι συμβάσεις χωρίς πελάτη ταιριάζουν');
    line('συνήθως χρονικά μεταξύ τους — σύγκρινε created_at/updated_at παραπάνω. Η');
    line('επανασύνδεση γίνεται με το χέρι, ανά ζεύγος, όχι με script: το script δεν');
    line('ξέρει ποιος πελάτης πήγαινε σε ποια σύμβαση, το ξέρει ο συνεργάτης.');
}

line();
line('Καμία εγγραφή δεν άλλαξε. Τίποτα δεν διορθώθηκε αυτόματα.');
line();

exit(0);
