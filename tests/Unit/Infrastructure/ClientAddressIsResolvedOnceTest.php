<?php

/**
 * Μία απάντηση στο «από πού ήρθε αυτή η αίτηση», όχι δύο.
 *
 * ## Γιατί υπάρχει
 *
 * Ο `ClientIp` γράφτηκε στις 17/08/2026 ακριβώς επειδή τα forwarded headers τα
 * πληκτρολογεί ο καλών: διαβάζει την αλυσίδα από δεξιά και πιστεύει μόνο proxy
 * που έχουμε δηλώσει. Είναι σωστός και δοκιμασμένος.
 *
 * Και είχε **έναν** καλούντα. Τα τρία σημεία που καταγράφουν διεύθυνση ως
 * νομικό τεκμήριο — `consent_ip` και δύο `signed_ip` — διάβαζαν
 * `$_SERVER['REMOTE_ADDR']` κατευθείαν. Πίσω από Cloudflare θα κατέγραφαν όλα
 * την ίδια διεύθυνση, και **τίποτα δεν θα έσπαγε**: η στήλη θα γέμιζε με λάθος
 * τιμή και θα το μάθαινε κανείς μόνο όταν χρειαζόταν να την επικαλεστεί.
 *
 * Δεν ήταν αμέλεια. Η σωστή απάντηση υπήρχε αλλά είχε το όνομα κάποιου άλλου:
 * ζούσε μέσα στο `ECRM_RateLimit::ip()`, και κανείς δεν ψάχνει στον rate
 * limiter για το πώς καταγράφεται μια συναίνεση.
 *
 * ## Τι κάνει
 *
 * Μοιάζει με το `NoRemoteFontsTest` και για τον ίδιο λόγο: το πρόβλημα δεν είναι
 * bug που θα το πιάσει δοκιμή συμπεριφοράς, αλλά **μία γραμμή που φαίνεται
 * αθώα σε review**. `$_SERVER['REMOTE_ADDR']` είναι ό,τι θα γράψει ο επόμενος
 * που χρειάζεται μια IP, και θα δουλέψει μια χαρά στο localhost.
 *
 * ## Γιατί tokens και όχι regex
 *
 * Η πρώτη γραφή έκοβε τα σχόλια με regex ώστε ένα σχόλιο που *εξηγεί* γιατί δεν
 * διαβάζεται το REMOTE_ADDR να μην κοκκινίζει. Το `//[^\n]*` όμως κόβει και ό,τι
 * ακολουθεί ένα `https://` στην ίδια γραμμή: ο φύλακας θα έχανε σιωπηλά κώδικα.
 * Ένας φύλακας που κοιτάζει λιγότερα απ' όσα νομίζει είναι χειρότερος από
 * κανέναν. Ο `token_get_all()` ξεχωρίζει σχόλιο από κώδικα επειδή είναι ο ίδιος
 * ο parser της PHP.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Infrastructure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientAddressIsResolvedOnceTest extends TestCase
{
    /**
     * Το μόνο αρχείο που επιτρέπεται να διαβάσει τον superglobal γι' αυτό.
     *
     * Ο `ClientIp` ΔΕΝ είναι εδώ, και δεν είναι παράλειψη: δέχεται τον πίνακα ως
     * όρισμα και δεν αγγίζει ποτέ το `$_SERVER`. Γι' αυτό τρέχει σε unit test
     * χωρίς WordPress, και γι' αυτό ο φύλακας τον ελέγχει σαν κάθε άλλο αρχείο.
     */
    private const RESOLVER = 'src/Infrastructure/RequestIp.php';

    /**
     * Οι τρεις πηγές που απαντούν «ποιος καλεί», με τρεις διαφορετικούς βαθμούς
     * αξιοπιστίας — που είναι ακριβώς ο λόγος να μην τις διαβάζει ο καθένας.
     *
     * @var list<string>
     */
    private const ADDRESS_KEYS = [
        'REMOTE_ADDR',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CF_CONNECTING_IP',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Κάθε δικό μας αρχείο PHP εκτός του resolver.
     *
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

                // tFPDF είναι ξένος κώδικας· ο resolver έχει δικό του test.
                if (str_starts_with($relative, 'includes/lib/') || $relative === self::RESOLVER) {
                    continue;
                }

                $out[] = [$relative];
            }
        }

        return $out;
    }

    #[DataProvider('phpFiles')]
    public function testOnlyTheResolverReadsTheRequestAddress(string $relative): void
    {
        $found = self::addressKeysReadFrom(self::root() . '/' . $relative);

        self::assertSame(
            [],
            $found,
            "Το {$relative} διαβάζει το \$_SERVER για διεύθυνση: " . implode(', ', $found) . ".\n\n"
            . "Χρησιμοποίησε EnergyCRM\\Infrastructure\\RequestIp::current(). Το REMOTE_ADDR είναι\n"
            . "ο proxy όταν υπάρχει proxy, και τα δύο headers τα πληκτρολογεί ο καλών — ο\n"
            . "ClientIp ξέρει ποιο να πιστέψει και πότε, και ο RequestIp του δίνει τη ρύθμιση\n"
            . "του site.\n\n"
            . "Αν αυτό το αρχείο είναι όντως νέος resolver, πες το εδώ με τον λόγο δίπλα. Δύο\n"
            . 'απαντήσεις στο «ποιος καλεί» είναι ήδη μία πολλή.'
        );
    }

    /**
     * Ο σαρωτής όντως κοιτάζει κάτι, και ο resolver όντως είναι resolver.
     *
     * Χωρίς αυτό, ένα μετονομασμένο αρχείο ή ένας iterator που σταμάτησε να
     * βρίσκει τα πάντα μετατρέπει το παραπάνω σε test που περνάει πάντα — το
     * ακριβότερο είδος πράσινου.
     */
    public function testTheSweepIsActuallyLookingAtSomething(): void
    {
        $files = array_column(self::phpFiles(), 0);

        self::assertGreaterThan(150, count($files), 'Βρέθηκαν σχεδόν καθόλου αρχεία — μετακινήθηκαν;');
        self::assertNotContains(self::RESOLVER, $files, 'Ο resolver δεν πρέπει να ελέγχεται από τον εαυτό του.');

        // Ο RequestIp ονομάζει μόνο τα δύο forwarded headers, για την
        // προειδοποίηση. Το REMOTE_ADDR δεν το ονομάζει ποτέ: περνά ΟΛΟΚΛΗΡΟ το
        // $_SERVER στον ClientIp, που είναι εκείνος που ξέρει τι να κοιτάξει.
        // Αν αυτό γίνει τρία, κάποιος έβαλε δεύτερη ανάγνωση εδώ.
        self::assertSame(
            ['HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP'],
            self::addressKeysReadFrom(self::root() . '/' . self::RESOLVER),
            'Αν άδειασε, ο ανιχνευτής δεν πυροδοτεί πια και το test από πάνω περνάει πάντα.'
        );

        // Και ο καθαρός resolver εξακολουθεί να λύνει το REMOTE_ADDR — από
        // παράμετρο, γι' αυτό δεν πέφτει στον φύλακα.
        self::assertStringContainsString(
            "\$server['REMOTE_ADDR']",
            (string) file_get_contents(self::root() . '/src/Infrastructure/ClientIp.php'),
            'Ο ClientIp δεν διαβάζει πια REMOTE_ADDR· τότε τι λύνει ο RequestIp;'
        );
    }

    /**
     * Ποια από τα ADDRESS_KEYS εμφανίζονται ως **κώδικας** σε αρχείο που
     * αγγίζει τον superglobal. Τα σχόλια δεν μετράνε: ο token_get_all τα
     * ξεχωρίζει, οπότε ένα σχόλιο που εξηγεί γιατί ΔΕΝ διαβάζεται δεν κοκκινίζει.
     *
     * @return list<string>
     */
    private static function addressKeysReadFrom(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));

        $touchesServer = false;
        $literals      = [];

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_VARIABLE && $token[1] === '$_SERVER') {
                $touchesServer = true;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = trim($token[1], "'\"");
            }
        }

        if (! $touchesServer) {
            return [];
        }

        return array_values(array_filter(
            self::ADDRESS_KEYS,
            static fn (string $key): bool => in_array($key, $literals, true)
        ));
    }
}
