<?php

/**
 * Τα είδη εγγράφων που ΦΤΙΑΧΝΕΙ το ίδιο το σύστημα -- όχι δικαιολογητικά.
 *
 * ## Το πρόβλημα που λύνει
 *
 * Στον ίδιο πίνακα αρχείων ζουν δύο εντελώς διαφορετικά πράγματα: ό,τι
 * ανέβασε ο συνεργάτης/πελάτης (ταυτότητα, λογαριασμός, IBAN...) και ό,τι
 * παράγει ο κώδικας μόνος του -- η αίτηση (`contract`), τα φύλλα της
 * (`form_*`), το υπογεγραμμένο αντίγραφο (`signed_pdf`) και οι εικόνες
 * υπογραφής (`signature`, `signature_energy`). Κανείς δεν τα ξεχώριζε, οπότε:
 *
 * 1. Η καρτέλα τα έδειχνε στα «Έγγραφα» ανάμεσα στα δικαιολογητικά, με
 *    dropdown είδους που -- αφού κανένα από αυτά δεν υπάρχει στον κατάλογο
 *    `ECRM_Docs::kinds()` -- έπεφτε πάντα στο πρώτο: «Ταυτότητα/Διαβατήριο».
 * 2. Η αυτόματη ανάγνωση (`DocumentKindReview`) τα έστελνε στο μοντέλο σαν
 *    άγνωστα έγγραφα -- πληρωμένη κλήση για χαρτί που ξέρουμε ήδη τι είναι, και
 *    ξανά σε ΚΑΘΕ αναπαραγωγή της αίτησης (το `store()` σβήνει και ξαναγράφει
 *    τις γραμμές, άρα γυρίζουν «αδιάβαστες»).
 * 3. Το dropdown και η ανάγνωση μπορούσαν να αλλάξουν το `doc_kind` μιας
 *    υπογραφής σε κάτι άλλο -- και τότε το `latestPathOfKind('signature')` δεν
 *    τη βρίσκει, και η υπογραφή χάνεται από κάθε έντυπο.
 *
 * Μία λίστα, εδώ, και όλοι οι παραπάνω τη ρωτούν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Document;

use EnergyCRM\Domain\Contract\SignatureRoles;

final class SystemKind
{
    /**
     * Η αίτηση σε όλες της τις μορφές. Ίδιες τιμές με `ContractDocuments::KIND`
     * και το `signed_pdf` του `ECRM_Tracking::rest_sign()` -- το τεστ του
     * κρατά ευθυγραμμισμένα.
     *
     * @var list<string>
     */
    public const APPLICATION = ['contract', 'signed_pdf'];

    /** Κάθε επιπλέον φύλλο της αίτησης: `ContractDocuments::SHEET_PREFIX`. */
    public const SHEET_PREFIX = 'form_';

    /**
     * Αίτηση/φύλλο/υπογεγραμμένο αντίγραφο. Δεν ανήκει στα «Έγγραφα»: έχει το
     * δικό του κουμπί «PDF έντυπο», που δίνει πάντα την τελευταία εκδοχή.
     */
    public static function isApplication(string $kind): bool
    {
        return in_array($kind, self::APPLICATION, true)
            || str_starts_with($kind, self::SHEET_PREFIX);
    }

    /** Εικόνα υπογραφής, οποιουδήποτε ρόλου. */
    public static function isSignature(string $kind): bool
    {
        return $kind !== '' && in_array($kind, SignatureRoles::kinds(), true);
    }

    /** Ο,τι έφτιαξε το σύστημα -- ποτέ προς ανάγνωση, ποτέ προς αλλαγή είδους. */
    public static function isSystem(string $kind): bool
    {
        return self::isApplication($kind) || self::isSignature($kind);
    }

    /**
     * Η σταθερή ετικέτα όσων φαίνονται στα «Έγγραφα» χωρίς επιλογή είδους,
     * ή null για ό,τι δεν είναι δικό μας.
     */
    public static function label(string $kind): ?string
    {
        if (! self::isSignature($kind)) {
            return null;
        }

        // Με δύο πρόσωπα (COMBO) πρέπει να φαίνεται ποιανού είναι ποια.
        return $kind === SignatureRoles::kindFor(SignatureRoles::ENERGY)
            ? 'Υπογραφή (πελάτης ενέργειας)'
            : 'Υπογραφή';
    }
}
