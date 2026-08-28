<?php

/**
 * Το `src/Domain/` δεν ξέρει από WordPress — και όποιο ξέρει, το ξέρει επώνυμα.
 *
 * ## Γιατί υπάρχει αυτό το αρχείο
 *
 * Το `HANDOVER.md` §1.12 είναι ρητή εντολή του ιδιοκτήτη (23/08/2026): κάποια
 * στιγμή το σύστημα φεύγει από WordPress και ξαναχτίζεται σε Laravel, και το
 * `src/Domain/*` είναι το κομμάτι που πρέπει να μεταφερθεί **αυτούσιο**. Ο
 * κανόνας απαγορεύει ρητά `wp_*`, `$wpdb`, `current_user_can`, hooks και
 * WordPress globals εκεί μέσα.
 *
 * Ο κανόνας ήταν γραμμένος, λεπτομερής, και **ξεχάστηκε**: η συνοδευτική του
 * πρακτική (γραμμή «Laravel-ready;» σε κάθε εγγραφή CHANGELOG) εφαρμόστηκε
 * στις εγγραφές (98)-(108) και μετά σταμάτησε σιωπηλά για πενήντα εγγραφές.
 * Όχι επειδή ανακλήθηκε — επειδή άλλαξε συνεδρία και κανείς δεν το θυμήθηκε.
 * Έγγραφο που περιγράφει κανόνα δεν επιβάλλει κανόνα. Δομικός έλεγχος ναι.
 *
 * ## Γιατί λίστα εξαιρέσεων και όχι μηδέν
 *
 * Τη στιγμή που γράφτηκε, τρία αρχεία του `Domain` **ήδη** καλούσαν
 * WordPress. Ένας έλεγχος που απαιτούσε μηδέν θα ήταν κόκκινος από την πρώτη
 * μέρα, και ένας κόκκινος έλεγχος που κανείς δεν σκοπεύει να πρασινίσει
 * σήμερα απενεργοποιείται σε μια βδομάδα — οπότε δεν θα προστάτευε τίποτα.
 *
 * Αυτό που κάνει αντ' αυτού: **κλειδώνει το «μέχρι εδώ»**. Τα τρία υπάρχοντα
 * είναι γραμμένα με τον λόγο τους. Το τέταρτο κοκκινίζει τη σουίτα.
 *
 * Δεν είναι άδεια: κάθε ένα από τα τρία είναι χρέος του §1.12 και πληρώνεται
 * όταν αγγιχτεί το αρχείο ούτως ή άλλως.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class DomainStaysFrameworkFreeTest extends TestCase
{
    /**
     * Τα αρχεία του Domain που επιτρέπεται να αγγίζουν WordPress, με τον λόγο.
     *
     * @var array<string, string>
     */
    private const ALLOWED = [
        'src/Domain/Contract/AutoProcess.php' =>
            'Είναι χρονοπρογραμματισμός: ζει πάνω σε WP-Cron (wp_schedule_event, '
            . 'cron_schedules) και σε hooks. Στην πραγματικότητα είναι Infrastructure '
            . 'με domain όνομα — η μετακόμισή του είναι χρέος του §1.12, όχι εξαίρεση '
            . 'από αυτό.',

        'src/Domain/Contract/ContractLifecycle.php' =>
            'Ένα do_action: το ecrm_contract_status_changed, που ειδοποιεί τον '
            . 'AutoProcess. Είναι το σημείο εξόδου του domain προς την πλατφόρμα και '
            . 'σε Laravel γίνεται event dispatch — μία γραμμή, όχι διάχυτη εξάρτηση.',

        'src/Domain/Contract/ExtraFields.php' =>
            'wp_json_encode αντί για json_encode. Καθαρά ευκολία της πλατφόρμας για '
            . 'κωδικοποίηση JSON· το φθηνότερο από τα τρία να φύγει.',
    ];

    /**
     * Ό,τι κάνει έναν κώδικα δεμένο με WordPress, όπως το ορίζει το §1.12.
     *
     * Υπερκάλυψη επίτηδες: το κόστος ενός ψευδώς θετικού είναι μια γραμμή στη
     * ALLOWED με τον λόγο δίπλα. Το κόστος ενός ψευδώς αρνητικού είναι ότι ο
     * κανόνας ξαναξεχνιέται σιωπηλά, που είναι ακριβώς ό,τι ήδη συνέβη.
     */
    private const WORDPRESS = '/\$wpdb'
        . '|\bwp_[a-z0-9_]+\s*\('
        . '|\b(?:add_action|add_filter|apply_filters|do_action|remove_action|remove_filter|has_action)\s*\('
        . '|\b(?:current_user_can|get_current_user_id|get_userdata|user_can)\s*\('
        . '|\b(?:get_user_meta|update_user_meta|delete_user_meta)\s*\('
        . '|\b(?:get_option|update_option|delete_option|get_transient|set_transient)\s*\('
        . '|\b(?:esc_html|esc_attr|esc_url|sanitize_text_field|sanitize_key)\s*\(/';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @return list<string> Σχετικές διαδρομές, ταξινομημένες.
     */
    private static function domainFilesTouchingWordPress(): array
    {
        $root  = self::root();
        $found = [];

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $it */
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/src/Domain', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match(self::WORDPRESS, $source) === 1) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                $found[$relative] = true;
            }
        }

        $files = array_keys($found);
        sort($files);

        return $files;
    }

    public function testNoNewDomainFileReachesForWordPress(): void
    {
        $allowed = array_keys(self::ALLOWED);
        sort($allowed);

        self::assertSame(
            $allowed,
            self::domainFilesTouchingWordPress(),
            "Το σύνολο των αρχείων του src/Domain που αγγίζουν WordPress άλλαξε.\n\n"
            . "Αν πρόσθεσες ένα: το HANDOVER.md §1.12 λέει ότι το Domain μεταφέρεται\n"
            . "ΑΥΤΟΥΣΙΟ σε Laravel. Ό,τι καλεί wp_*, \$wpdb, hooks ή capabilities δεν\n"
            . "μεταφέρεται — ξαναγράφεται. Βάλε τη λογική σε καθαρή κλάση και άφησε την\n"
            . "πλατφόρμα απ' έξω (Persistence για βάση, Infrastructure για cron/HTTP/\n"
            . "αρχεία, Http για αίτημα και απάντηση).\n\n"
            . "Αν είναι πραγματικά αναπόφευκτο, πρόσθεσέ το στη ALLOWED ΜΕ ΤΟΝ ΛΟΓΟ —\n"
            . 'και ξέρε ότι γράφεις χρέος, όχι εξαίρεση.'
        );
    }

    /**
     * Ο σαρωτής όντως βρίσκει.
     *
     * Ένα regex που παύει να ταιριάζει μετατρέπει τον έλεγχο σε test που
     * περνάει πάντα — και εδώ θα σήμαινε «το Domain είναι καθαρό», που τη
     * στιγμή που γράφεται αυτό είναι ψέμα.
     */
    public function testTheSweepStillSeesTheKnownOffenders(): void
    {
        self::assertContains(
            'src/Domain/Contract/AutoProcess.php',
            self::domainFilesTouchingWordPress(),
            'Ο σαρωτής δεν βλέπει πια ούτε τον AutoProcess, που είναι γεμάτος WP-Cron. '
            . 'Το regex έπαψε να ταιριάζει.'
        );
    }
}
