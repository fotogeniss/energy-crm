<?php

/**
 * Ποιος πρέπει να υπογράψει μια αίτηση, και πού ζει η υπογραφή του καθενός.
 *
 * Ώς τις 04/09/2026 η απάντηση ήταν πάντα «ένας». Το `contracts.signed_at`
 * είναι μία δυαδική στήλη, η εικόνα αποθηκεύεται ως ένα αρχείο με
 * `doc_kind = 'signature'`, και η εκτύπωση στάμπαρε εκείνη τη μία εικόνα σε
 * **κάθε** θέση υπογραφής του χάρτη.
 *
 * Για όλα τα έντυπα εκτός ενός αυτό είναι σωστό: ο ίδιος άνθρωπος υπογράφει σε
 * τρία σημεία του ίδιου χαρτιού. Το COMBO όμως έχει δύο γραμμές που ανήκουν σε
 * **δύο πιθανώς διαφορετικούς ανθρώπους** -- «ΥΠΟΓΡΑΦΗ ΠΕΛΑΤΗ ΚΙΝΗΤΗΣ» και
 * «ΥΠΟΓΡΑΦΗ ΠΕΛΑΤΗΣ ΕΝΕΡΓΕΙΑΣ» -- και εκεί η ίδια εικόνα δύο φορές σημαίνει
 * έγγραφο που δείχνει τον έναν να έχει υπογράψει στη θέση του άλλου.
 *
 * ## Γιατί δεν μπήκε στήλη στον πίνακα `signatures`
 *
 * Γιατί ο πίνακας `ecrm_signatures` **δεν γράφεται ποτέ**. Υπάρχει στο σχήμα
 * από την αρχή, έχει `token`/`signer_name`/`image`, και οι μόνες αναφορές του
 * σε όλο τον κώδικα είναι μια ετικέτα GDPR, ο διαγραφέας προσωπικών δεδομένων
 * και ένα foreign key. Καμία εγγραφή. Η πραγματική υπογραφή ζει στο `files`.
 * Στήλη σε νεκρό πίνακα θα ήταν τεκμηρίωση μιας αρχιτεκτονικής που δεν υπάρχει.
 *
 * Το `files.doc_kind` είναι ελεύθερο VARCHAR(24) χωρίς λίστα επιτρεπτών τιμών,
 * οπότε ένα δεύτερο kind αρκεί -- **καμία αλλαγή σχήματος, κανένα migration**.
 * Οι υπογραφές που υπάρχουν ήδη μένουν ακριβώς όπου είναι.
 *
 * Καθαρό PHP: καμία γνώση βάσης, κανένα `wp_*`. Ο κανόνας είναι του εντύπου.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

use EnergyCRM\Domain\Forms\MobilePaperwork;

final class SignatureRoles
{
    /** Ο πελάτης της γραμμής κινητής -- ο μόνος υπογράφων σε κάθε άλλο έντυπο. */
    public const MOBILE = 'mobile';

    /** Ο πελάτης της παροχής ρεύματος, όταν είναι άλλο πρόσωπο. */
    public const ENERGY = 'energy';

    /**
     * Το `files.doc_kind` κάθε ρόλου.
     *
     * Ο ρόλος `mobile` κρατά το ιστορικό όνομα `signature`: κάθε υπογραφή που
     * υπάρχει σήμερα είναι αυτού του είδους, και μια μετονομασία θα τις έκανε
     * όλες αόρατες χωρίς να σπάσει τίποτα ορατά.
     *
     * @var array<string, string>
     */
    private const KINDS = [
        self::MOBILE => 'signature',
        self::ENERGY => 'signature_energy',
    ];

    private function __construct()
    {
    }

    /**
     * Ποιοι ρόλοι πρέπει να υπογράψουν αυτή την αίτηση.
     *
     * Δύο υπογραφές **μόνο** όταν είναι δύο πρόσωπα -- ρητή απόφαση του
     * ιδιοκτήτη (04/09/2026). Όταν ο πελάτης ενέργειας είναι ο ίδιος άνθρωπος,
     * υπογράφει μία φορά και η ίδια εικόνα μπαίνει και στις δύο γραμμές· είναι
     * όντως η υπογραφή του, σε δύο θέσεις που τον αφορούν και τις δύο.
     *
     * @return list<string>
     */
    public static function requiredFor(string $offer, bool $energyIsSamePerson): array
    {
        if ($offer !== MobilePaperwork::OFFER_COMBO || $energyIsSamePerson) {
            return [self::MOBILE];
        }

        return [self::MOBILE, self::ENERGY];
    }

    /**
     * Ποιες υπογραφές λείπουν ακόμα.
     *
     * @param list<string> $required
     * @param list<string> $collected
     *
     * @return list<string>
     */
    public static function missing(array $required, array $collected): array
    {
        return array_values(array_diff($required, $collected));
    }

    /**
     * Έχουν υπογράψει όλοι όσοι έπρεπε;
     *
     * Το `signed_at` της σύμβασης παύει να σημαίνει «κάποιος υπέγραψε» και
     * σημαίνει «υπέγραψαν όλοι» -- ρητή απόφαση του ιδιοκτήτη (04/09/2026),
     * ώστε να μην μπορεί να φύγει στον πάροχο μισοϋπογεγραμμένο έντυπο.
     *
     * @param list<string> $required
     * @param list<string> $collected
     */
    public static function isComplete(array $required, array $collected): bool
    {
        return self::missing($required, $collected) === [];
    }

    public static function isRole(string $role): bool
    {
        return isset(self::KINDS[$role]);
    }

    /** Το doc_kind ενός ρόλου, ή '' αν ο ρόλος δεν είναι δικός μας. */
    public static function kindFor(string $role): string
    {
        return self::KINDS[$role] ?? '';
    }

    /**
     * Ρόλος => doc_kind, για όποιον χτίζει ολόκληρο τον χάρτη υπογραφών.
     *
     * @return array<string, string>
     */
    public static function kinds(): array
    {
        return self::KINDS;
    }
}
