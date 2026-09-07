<?php

/**
 * Έχει αυτή η αίτηση γραμμή κινητής;
 *
 * Μία ερώτηση, ένα σημείο. Τη ρωτά η ροή των καταστάσεων για να αποφασίσει αν
 * η αίτηση περνά από την ΑΝΑΜΟΝΗ ΠΑΡΑΔΟΣΗΣ SIM ή την προσπερνά (κανόνας Κ2 του
 * `docs/STATUS-MODEL.md`): SIM παραδίδεται μόνο εκεί που υπάρχει γραμμή.
 *
 * ## Γιατί δεν είναι απλώς `energy_type === 'mobile'`
 *
 * Δύο διαφορετικές αιτήσεις καταλήγουν με γραμμή κινητής:
 *
 * 1. **Αίτηση Orizon** (`energy_type = 'mobile'`) -- η ίδια η αίτηση ΕΙΝΑΙ η
 *    γραμμή.
 * 2. **Αίτηση ενέργειας με combo** -- ρεύμα ή αέριο που κουβαλά και κινητή,
 *    και τότε η προσφορά ζει στο `combo_mobile_offer` του `extra_json`.
 *
 * Τα ονόματα των πεδίων δεν επιλέχθηκαν εδώ: είναι ακριβώς αυτά που ήδη
 * διαβάζει το `SignatureState::requiredFrom()` για να κρίνει πόσες υπογραφές
 * χρειάζονται, και ο λόγος που διαφέρουν ανά προέλευση εξηγείται εκεί (οι δύο
 * κάρτες ζουν ταυτόχρονα στο DOM και δεν μπορούν να μοιράζονται `name`). Αν
 * αυτά αλλάξουν, αλλάζουν και στα δύο σημεία -- γι' αυτό το σχόλιο δείχνει
 * εκεί αντί να αντιγράφει τον κανόνα.
 *
 * ## Τι ΔΕΝ ρωτάει
 *
 * Δεν ρωτά αν έχει ήδη σταλεί ή παραδοθεί SIM. Ρωτά μόνο αν *υπάρχει* γραμμή.
 * Το πού βρίσκεται η παράδοση το λέει η κατάσταση, όχι αυτό.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Contract;

use EnergyCRM\Domain\Forms\MobilePaperwork;

final class MobileLine
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $contract Πρέπει να έχει `energy_type` και
     *                                       `extra_json` (raw στήλη).
     */
    public static function isPresent(array $contract): bool
    {
        if ((string) ($contract['energy_type'] ?? '') === 'mobile') {
            return true;
        }

        $extra = json_decode((string) ($contract['extra_json'] ?? ''), true);
        $extra = is_array($extra) ? $extra : [];

        $offer = (string) ($extra['combo_mobile_offer'] ?? '');

        // Το OFFER_NONE είναι ήδη '' -- η σύγκριση με αυτό είναι η ίδια
        // σύγκριση με ''. Μία φορά αρκεί.
        return $offer !== MobilePaperwork::OFFER_NONE;
    }

    /**
     * Το στάδιο που ακολουθεί την υπογραφή.
     *
     * Εδώ και όχι μέσα στο `ContractStatus`: ο γράφος ξέρει ποιες μεταβάσεις
     * *επιτρέπονται* (και επιτρέπει και τις δύο), ενώ ποια από τις δύο ισχύει
     * το κρίνει το περιεχόμενο της αίτησης. Δύο διαφορετικές ερωτήσεις, δύο
     * διαφορετικά αρχεία.
     *
     * @param array<string, mixed> $contract
     */
    public static function stageAfterSignature(array $contract): ContractStatus
    {
        return self::isPresent($contract)
            ? ContractStatus::AwaitingSim
            : ContractStatus::Finalisation;
    }
}
