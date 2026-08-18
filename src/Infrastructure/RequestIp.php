<?php

/**
 * Η διεύθυνση της τρέχουσας αίτησης, με τη ρύθμιση του site εφαρμοσμένη.
 *
 * Ο `ClientIp` ξέρει **πώς** διαβάζεται μια αλυσίδα proxy και είναι σκόπιμα
 * χωρίς WordPress, ώστε η απόφαση να τρέχει σε unit test. Λείπει το μισό: από
 * πού έρχεται η λίστα των έμπιστων proxy, και τι γίνεται όταν κανείς δεν την
 * όρισε. Αυτό ζούσε μέσα στο `ECRM_RateLimit::ip()`.
 *
 * Το ότι ζούσε εκεί δεν ήταν λάθος τοποθέτηση· ήταν η αιτία ενός λάθους.
 *
 * ## Τι έσπασε επειδή έλειπε αυτή η κλάση
 *
 * Ο `ClientIp` γράφτηκε στις 17/08/2026 και είχε **έναν** καλούντα: τον rate
 * limiter. Τα τρία σημεία που καταγράφουν διεύθυνση ως **νομικό τεκμήριο** —
 * `consent_ip` στην αποθήκευση σύμβασης, `signed_ip` στις δύο διαδρομές
 * υπογραφής — διάβαζαν `$_SERVER['REMOTE_ADDR']` κατευθείαν.
 *
 * Πίσω από Cloudflare, που ο ίδιος ο `ClientIp` προβλέπει ονομαστικά, και τα
 * τρία θα κατέγραφαν τη διεύθυνση του edge node. **Κάθε** συναίνεση και **κάθε**
 * υπογραφή θα είχε την ίδια IP. Το CHANGELOG (2521) ονομάζει το `consent_ip`
 * «απόδειξη συναίνεσης του GDPR»· απόδειξη που δείχνει το Cloudflare δεν είναι
 * απόδειξη. Και τίποτα δεν θα έσπαγε: η στήλη απλώς θα γέμιζε με λάθος τιμή.
 *
 * Ο λόγος που κράτησε τόσο είναι ότι η σωστή απάντηση υπήρχε αλλά είχε το
 * όνομα κάποιου άλλου. Κανείς δεν ψάχνει στον rate limiter για το πώς
 * καταγράφεται μια συναίνεση. Το `ClientAddressIsResolvedOnceTest` είναι ο
 * φύλακας που δεν το αφήνει να ξαναγίνει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

final class RequestIp
{
    /** Το φίλτρο και η επιλογή που δηλώνουν τους δικούς μας proxy. */
    public const TRUSTED = 'ecrm_trusted_proxies';

    /** Μία προειδοποίηση την ημέρα, όχι μία ανά αίτηση. */
    private const WARNED = 'ecrm_rl_proxy_warned';

    /**
     * Το μήκος μιας IPv6 με zone index στη μεγαλύτερη μορφή της.
     *
     * Ο `ClientIp` επιστρέφει μόνο τιμές που πέρασαν από `FILTER_VALIDATE_IP`,
     * άρα δεν το ξεπερνά ποτέ. Μένει επειδή οι στήλες είναι `VARCHAR(64)` και
     * μια σιωπηλή περικοπή σε τεκμήριο είναι χειρότερη από μια ρητή.
     */
    private const MAX_LENGTH = 45;

    private function __construct()
    {
    }

    /** Η διεύθυνση της τρέχουσας αίτησης, ή '' όταν δεν υπάρχει χρησιμοποιήσιμη. */
    public static function current(): string
    {
        $trusted = self::trustedProxies();

        if ($trusted === []) {
            self::warnIfProxied();
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ο ClientIp περνά κάθε τιμή που χρησιμοποιεί από FILTER_VALIDATE_IP· ένα sanitize_text_field εδώ θα «καθάριζε» μια τιμή που έτσι κι αλλιώς απορρίπτεται αν δεν είναι IP.
        $server = array_map('wp_unslash', $_SERVER);

        return substr((new ClientIp($trusted))->resolve($server), 0, self::MAX_LENGTH);
    }

    /**
     * Οι proxy που βάλαμε εμείς μπροστά από το site.
     *
     * Κενή λίστα σημαίνει «κανένας», άρα κανένα forwarded header δεν πιστεύεται.
     * Είναι η αυστηρή προεπιλογή και η σωστή: ένα header που δεν εγγυάται
     * κανείς είναι κείμενο που πληκτρολογεί ο καλών.
     *
     * @return list<string>
     */
    public static function trustedProxies(): array
    {
        /*
         * Το όνομα του hook είναι η σταθερά από πάνω. Ο sniff δεν διαβάζει μέσα
         * σε σταθερά και το θεωρεί δυναμικό· γραμμένο ως literal εδώ θα ήταν η
         * ίδια συμβολοσειρά σε δύο σημεία — ακριβώς αυτό που η σταθερά αποτρέπει.
         * Ίδιο σκεπτικό με το do_action στο Domain\Contract\ContractLifecycle.
         */
        /** @var mixed $configured */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
        $configured = apply_filters(self::TRUSTED, get_option(self::TRUSTED, []));

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $configured
        )));
    }

    /**
     * Πες το, μία φορά την ημέρα, όταν το site είναι πίσω από proxy που κανείς
     * δεν δήλωσε.
     *
     * Η σιωπή εδώ θα έμοιαζε με rate limiting που δουλεύει ενώ κάθε επισκέπτης
     * μετράει ως ο ίδιος πελάτης — και, από τη μέρα που αυτή η κλάση σερβίρει
     * και τα τεκμήρια, με `consent_ip` που δείχνει όλα την ίδια διεύθυνση.
     */
    private static function warnIfProxied(): void
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- δεν διαβάζεται τιμή, μόνο η ΥΠΑΡΞΗ του header.
        $proxied = ! empty($_SERVER['HTTP_X_FORWARDED_FOR']) || ! empty($_SERVER['HTTP_CF_CONNECTING_IP']);

        if (! $proxied || get_transient(self::WARNED)) {
            return;
        }

        set_transient(self::WARNED, 1, DAY_IN_SECONDS);

        error_log( // phpcs:ignore
            '[Energy CRM] Οι αιτήσεις έρχονται μέσω proxy αλλά δεν έχει οριστεί το φίλτρο '
            . 'ecrm_trusted_proxies· το rate limiting μετράει όλους τους επισκέπτες ως έναν, '
            . 'και τα consent_ip / signed_ip καταγράφουν τη διεύθυνση του proxy αντί του πελάτη.'
        );
    }
}
