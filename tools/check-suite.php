<?php

/**
 * Τρέχει το PHPUnit και ΑΠΑΙΤΕΙ γραμμή σύνοψης — όχι απλώς κωδικό εξόδου 0.
 *
 * ## Γιατί υπάρχει
 *
 * Μέσα σε δύο μέρες, τρεις διαφορετικοί μηχανισμοί έβγαλαν «πράσινο» χωρίς να
 * έχει τρέξει τίποτα, και **και οι τρεις** πέρασαν τον pre-commit hook:
 *
 *   1. Το `vendor/bin/phpunit` βρέθηκε **0 bytes**. Ένα άδειο αρχείο PHP
 *      εκτελείται, δεν κάνει τίποτα, και βγαίνει με κωδικό 0.
 *   2. Το `tools/test-db.cmd` καλούσε άλλο `.bat` χωρίς `call`, οπότε παρέδιδε
 *      τον έλεγχο και δεν τον ξαναέπαιρνε — σιωπηλή επιστροφή στο prompt.
 *   3. Ένα legacy αρχείο με τον φρουρό `if ( ! defined( 'ABSPATH' ) ) exit;`
 *      φορτώθηκε από unit test. Το `exit` χωρίς όρισμα είναι `exit(0)`, οπότε η
 *      σουίτα πέθαινε στο test 874 από τα 889 και ο composer προχωρούσε.
 *      Δες `docs/SUITE-874.html`.
 *
 * Το κοινό τους σχήμα δεν είναι το σφάλμα — είναι ότι **η απουσία πλήθους test
 * διαβάστηκε ως επιτυχία**. Ο κωδικός εξόδου δεν ξεχωρίζει το «πέρασε» από το
 * «δεν έτρεξε». Η γραμμή `OK (N tests, M assertions)` το ξεχωρίζει, γιατί το
 * PHPUnit την τυπώνει μόνο όταν φτάσει ως το τέλος.
 *
 * ## Τι κάνει
 *
 * Τρέχει το PHPUnit με τα ορίσματα που του δόθηκαν, δείχνει την έξοδό του
 * ζωντανά όπως πριν, και μετά ελέγχει ότι υπάρχει γραμμή σύνοψης. Αν λείπει,
 * βγαίνει **μη μηδενικός** ό,τι κι αν είπε το PHPUnit.
 *
 * Γράφει σε αρχεία αντί για σωλήνες επίτηδες: το `stream_select()` **δεν
 * δουλεύει σε pipes του `proc_open()` στα Windows**, και όλα εδώ τρέχουν στο
 * Site Shell (cmd.exe). Τα `*.tmp.txt` είναι ήδη στο `.gitignore` και μένουν
 * πίσω σκόπιμα: είναι η πλήρης έξοδος όταν το τερματικό την κόβει.
 *
 * Δεν είναι στο scope ούτε του phpcs ούτε του phpstan — το `tools/` δεν
 * μετράει πουθενά, οπότε αυτό το αρχείο δεν κουνά κανέναν αριθμό.
 *
 * ## Το bug που είχε αυτό το ίδιο το αρχείο, μετρημένο 2026-08-22
 *
 * Στην πρώτη πραγματική εκτέλεση ο έλεγχος απέτυχε **ενώ η σουίτα είχε
 * τελειώσει κανονικά** — «OK, but some tests were skipped! / Tests: 891,
 * Assertions: 2479, Skipped: 1.» ήταν ήδη στην οθόνη. Ψευδώς θετικό μπλοκάρισμα,
 * χειρότερο από αυτό που ήρθε να διορθώσει.
 *
 * Η αιτία βρέθηκε στα ίδια τα bytes του `phpunit-out.tmp.txt` (`od -c`), όχι με
 * υπόθεση: το `phpunit.xml.dist` έχει `colors="true"` — **αναγκαστικά** on, όχι
 * "auto" — οπότε το PHPUnit βάφει τη γραμμή σύνοψης με ANSI ακόμα κι όταν το
 * stdout πάει σε αρχείο και όχι σε τερματικό. Η πραγματική γραμμή ήταν
 * `\x1B[30;42mOK, but ...` — ο κώδικας χρώματος **πριν** το «OK», οπότε το
 * `^(OK \(|OK, but |Tests: )` δεν ταίριαζε ποτέ σε αρχή γραμμής.
 *
 * Η διόρθωση αφαιρεί τους κώδικες ANSI **μόνο** από το αντίγραφο που ελέγχεται
 * με regex — η ζωντανή έξοδος στο τερματικό μένει έγχρωμη, όπως πριν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$args = array_slice($argv, 1);

$outLog = $root . '/phpunit-out.tmp.txt';
$errLog = $root . '/phpunit-err.tmp.txt';

/*
 * Εντολές που δεν τρέχουν σουίτα δεν τυπώνουν σύνοψη, και σωστά κάνουν.
 * Χωρίς αυτή την εξαίρεση ένα `--version` θα κοκκίνιζε ως «δεν έτρεξε τίποτα».
 */
$isNotARun = false;
foreach ($args as $arg) {
    if ($arg === '--version' || $arg === '--help' || str_starts_with($arg, '--list')) {
        $isNotARun = true;
        break;
    }
}

$command = array_merge(
    [PHP_BINARY, '-d', 'max_execution_time=0', $root . '/vendor/bin/phpunit'],
    $args
);

$process = proc_open(
    $command,
    [
        1 => ['file', $outLog, 'w'],
        2 => ['file', $errLog, 'w'],
    ],
    $pipes,
    $root
);

if (! is_resource($process)) {
    fwrite(STDERR, "Δεν ξεκίνησε καν η διεργασία του PHPUnit.\n");
    exit(1);
}

/*
 * Δείχνουμε ό,τι γράφεται, όσο γράφεται. Η γραμμή προόδου με τις τελείες είναι
 * ο λόγος που το σφάλμα του 874 έγινε ορατό — δεν τη θυσιάζουμε για ευκολία.
 */
$shown    = 0;
$exitCode = null;

while (true) {
    $status = proc_get_status($process);
    $shown += ecrm_echo_new_bytes($outLog, $shown);

    if ($status['running'] === false) {
        $exitCode = $status['exitcode'];
        break;
    }

    usleep(100000);
}

// Η ουρά που προλαβαίνει να γραφτεί ανάμεσα στην τελευταία ανάγνωση και το τέλος.
ecrm_echo_new_bytes($outLog, $shown);
proc_close($process);

$output = is_file($outLog) ? (string) file_get_contents($outLog) : '';
$errors = is_file($errLog) ? trim((string) file_get_contents($errLog)) : '';

if ($errors !== '') {
    fwrite(STDERR, $errors . "\n");
}

if ($isNotARun) {
    exit(is_int($exitCode) ? $exitCode : 1);
}

/*
 * Το `phpunit.xml.dist` έχει colors="true" — αναγκαστικά, όχι "auto" — άρα το
 * PHPUnit βάφει τη σύνοψη με ANSI ακόμα κι όταν το stdout πάει σε αρχείο. Ο
 * κώδικας χρώματος (`\x1B[30;42m`) μπαίνει ΠΡΙΝ το «OK»/«Tests:», οπότε ένα
 * regex με `^` θα αστοχούσε πάντα χωρίς αυτό το καθάρισμα. Καθαρίζουμε μόνο το
 * αντίγραφο που ελέγχεται — η ζωντανή έξοδος στο τερματικό μένει έγχρωμη.
 */
$plain = preg_replace('/\x1B\[[0-9;]*m/', '', $output);

/*
 * «Δεν έτρεξε κανένα test» ΕΙΝΑΙ σύνοψη — το PHPUnit έφτασε ως το τέλος — αλλά
 * είναι και ακριβώς η αρρώστια που φυλάει αυτό το αρχείο. Χωριστό μήνυμα.
 */
if (preg_match('/^No tests executed/m', $plain) === 1) {
    ecrm_fail(
        'Το PHPUnit έτρεξε ως το τέλος αλλά ΔΕΝ ΕΚΤΕΛΕΣΕ ΚΑΝΕΝΑ TEST.',
        'Συνήθως λάθος διαδρομή σουίτας ή φίλτρο που δεν ταιριάζει σε τίποτα.',
        $outLog
    );
}

if (preg_match('/^(OK \(|OK, but |Tests: )/m', $plain) !== 1) {
    ecrm_fail(
        'Η ΣΟΥΙΤΑ ΔΕΝ ΤΕΛΕΙΩΣΕ: δεν τυπώθηκε γραμμή σύνοψης.',
        "Κωδικός εξόδου του PHPUnit: " . var_export($exitCode, true) . ".\n"
        . "Κωδικός 0 χωρίς σύνοψη σημαίνει ότι η PHP τερμάτισε καθαρά στη μέση —\n"
        . "συνήθως `exit`/`die` μέσα σε αρχείο που φόρτωσε κάποιο test, ή μηδενισμένο\n"
        . "`vendor/bin/phpunit`. Έλεγχος δύο δευτερολέπτων:\n"
        . "    php vendor\\bin\\phpunit --version\n"
        . "Αν δεν τυπώσει έκδοση:  composer reinstall phpunit/phpunit\n"
        . "Αλλιώς, το τελευταίο test που ξεκίνησε το λέει το event log:\n"
        . "    php vendor\\bin\\phpunit --log-events-text phpunit-events.tmp.txt",
        $outLog
    );
}

exit($exitCode === 0 ? 0 : 1);


/**
 * Τυπώνει ό,τι προστέθηκε στο αρχείο μετά το `$from` και επιστρέφει πόσα bytes ήταν.
 */
function ecrm_echo_new_bytes(string $path, int $from): int
{
    if (! is_file($path)) {
        return 0;
    }

    clearstatcache(true, $path);
    $size = (int) filesize($path);

    if ($size <= $from) {
        return 0;
    }

    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return 0;
    }

    fseek($handle, $from);
    $chunk = (string) stream_get_contents($handle);
    fclose($handle);

    echo $chunk;

    return strlen($chunk);
}

/**
 * Βγαίνει μη μηδενικός με μήνυμα που δεν μπορεί να προσπεραστεί με το μάτι.
 *
 */
function ecrm_fail(string $headline, string $detail, string $log): never
{
    $line = str_repeat('=', 72);

    fwrite(STDERR, "\n" . $line . "\n");
    fwrite(STDERR, $headline . "\n");
    fwrite(STDERR, $line . "\n");
    fwrite(STDERR, $detail . "\n\n");
    fwrite(STDERR, 'Πλήρης έξοδος: ' . $log . "\n");
    fwrite(STDERR, $line . "\n");

    exit(1);
}
