<?php

/**
 * Ποιοι ρόλοι πρέπει να υπογράψουν μια σύμβαση, και ποιοι το έχουν ήδη κάνει.
 *
 * `EnergyCRM\Domain\Contract\SignatureRoles` ξέρει τον ΚΑΝΟΝΑ (ποιοι ρόλοι
 * χρειάζονται, πότε είναι «όλοι υπέγραψαν») αλλά είναι καθαρό PHP -- καμία
 * γνώση βάσης, όπως λέει το ίδιο του το docblock. Το «ποιος έχει ΉΔΗ
 * υπογράψει» χρειάζεται ανάγνωση αρχείων (`files.doc_kind`), και αυτή η
 * κλάση είναι το ΜΟΝΟ σημείο που τα ενώνει.
 *
 * Δύο καλούντες θα χρειαστούν ακριβώς αυτό: το `SignLinkController` (ποιον
 * ρόλο να στείλει, και αν χρειάζεται καν) και το public-facing
 * `ECRM_Tracking` (τι να δείξει στον πελάτη που άνοιξε τον σύνδεσμό του). Ένα
 * δεύτερο αντίγραφο θα ήταν ακριβώς ο κίνδυνος που προειδοποιεί το
 * SignatureRoles::requiredFor() -- δύο σημεία να ξεχαστούν συγχρονισμένα.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use EnergyCRM\Domain\Contract\SignatureRoles;
use EnergyCRM\Persistence\FileRepository;

final class SignatureState
{
    public function __construct(private readonly FileRepository $files)
    {
    }

    /**
     * Ποιοι ρόλοι απαιτούνται, ποιοι έχουν ήδη υπογράψει, και αν είναι
     * ολοκληρωμένη -- για μια συγκεκριμένη σύμβαση.
     *
     * Τα πεδία της προσφοράς διαβάζονται απευθείας από το `extra_json`,
     * χωρίς αποκρυπτογράφηση: δεν είναι προσωπικά πεδία (βλ.
     * `ProviderFormFields::isPersonalInput()`) -- ίδιο μοτίβο με το `$xg()`
     * του `includes/class-ecrm-formfill.php`.
     *
     * @param array<string, mixed> $contract Πρέπει να έχει `extra_json` (raw
     *                                       στήλη) ΚΑΙ `energy_type`: από το
     *                                       Στάδιο 4 το δεύτερο κρίνει ΠΟΙΑ
     *                                       κλειδιά του σάκου ισχύουν.
     *
     * @return array{required: list<string>, collected: list<string>, complete: bool}
     */
    public function forContract(int $contractId, array $contract): array
    {
        $required = self::requiredFrom($contract);

        $collected = [];
        foreach (SignatureRoles::kinds() as $role => $kind) {
            if (in_array($role, $required, true) && $this->files->latestPathOfKind($contractId, $kind)) {
                $collected[] = $role;
            }
        }

        return [
            'required'  => $required,
            'collected' => $collected,
            'complete'  => SignatureRoles::isComplete($required, $collected),
        ];
    }

    /**
     * Ο κανόνας «ποιοι ρόλοι απαιτούνται», από τη γραμμή της σύμβασης.
     *
     * Τα πεδία της προσφοράς διαβάζονται απευθείας από το `extra_json`,
     * χωρίς αποκρυπτογράφηση: δεν είναι προσωπικά πεδία (βλ.
     * `ProviderFormFields::isPersonalInput()`) -- ίδιο μοτίβο με το `$xg()`
     * του `includes/class-ecrm-formfill.php`.
     *
     * @param array<string, mixed> $contract Πρέπει να έχει `extra_json` (raw
     *                                       στήλη) ΚΑΙ `energy_type`: από το
     *                                       Στάδιο 4 το δεύτερο κρίνει ΠΟΙΑ
     *                                       κλειδιά του σάκου ισχύουν.
     *
     * @return list<string>
     */
    private static function requiredFrom(array $contract): array
    {
        $extra = json_decode((string) ($contract['extra_json'] ?? ''), true);
        $extra = is_array($extra) ? $extra : [];

        // Σταδιο 4 (05/09/2026): το COMBO ξεκινα πλεον και απο αιτηση Volton,
        // και η καρτα «6γ» που το ρωταει εκει εχει ΔΙΚΑ ΤΗΣ ονοματα πεδιων --
        // `combo_mobile_offer`/`combo_mobile_same` -- ξεχωριστα απο τα
        // `mobile_offer`/`combo_energy_same` της Orizon-origin καρτας «6β».
        //
        // Οχι για καθαροτητα: οι δυο καρτες ζουν ΤΑΥΤΟΧΡΟΝΑ στο DOM (η μια
        // απλως κρυμμενη), οποτε κοινο `name` θα σημαινε δυο input με το ιδιο
        // ονομα, μια τιμη στο extra_json, και το setField() της επεξεργασιας
        // να γραφει παντα στο πρωτο κατα σειρα DOM -- δηλαδη στη λαθος καρτα.
        //
        // Ποιο ζευγος ισχυει το κρινει η ΠΡΟΕΛΕΥΣΗ της αιτησης, οχι η τιμη:
        // energy_type 'mobile' σημαινει αιτηση Orizon.
        $fromMobile = ((string) ($contract['energy_type'] ?? '')) === 'mobile';

        $offer = (string) ($extra[$fromMobile ? 'mobile_offer' : 'combo_mobile_offer'] ?? '');
        $raw   = (string) ($extra[$fromMobile ? 'combo_energy_same' : 'combo_mobile_same'] ?? '1');

        // Κρυπτογραφημένη τιμή σημαίνει «γράφτηκε πριν τη διόρθωση του (226),
        // με το ECRM_ENCRYPT_PII ανοιχτό» -- δεν ξέρουμε τι λέει, και το
        // σκέτο `!== '0'` θα την έλεγε «ίδιο πρόσωπο» επειδή ακριβώς δεν
        // μπορεί να τη διαβάσει. Αυτή η σιωπηλή απάντηση ΕΙΝΑΙ το bug: το
        // έντυπο έφευγε στον πάροχο με μία υπογραφή σε δύο γραμμές.
        //
        // Ασύμμετρο ρίσκο, ασύμμετρη επιλογή: «δύο υπογραφές» σε αίτηση ενός
        // προσώπου κολλάει ορατά και το αναφέρει ο πωλητής· «μία υπογραφή» σε
        // αίτηση δύο προσώπων φεύγει και δεν το μαθαίνει κανείς. Όταν δεν
        // ξέρουμε, ζητάμε τη δεύτερη -- ίδιο σκεπτικό με το
        // `SignatureRoles::isComplete()`, που υπάρχει ακριβώς γι' αυτό.
        $same = FieldCipher::isEncrypted($raw) ? false : $raw !== '0';

        return SignatureRoles::requiredFor($offer, $same);
    }

    /**
     * Το ίδιο, για ΠΟΛΛΕΣ συμβάσεις -- με ένα ερώτημα συνολικά.
     *
     * Η λίστα συμβάσεων χρειάζεται να ξέρει ποιες αιτήσεις περιμένουν ακόμα τη
     * δεύτερη υπογραφή. Η προφανής γραφή -- `forContract()` μέσα σε βρόχο --
     * είναι δύο ερωτήματα ανά γραμμή, δηλαδή 400 σε λίστα 200 γραμμών, και
     * ακριβώς το N+1 που αφαιρέθηκε από αυτόν τον ίδιο controller στο βήμα 3.
     * Ο κανόνας («ποιοι ρόλοι απαιτούνται») είναι ο ΙΔΙΟΣ κώδικας με την
     * `forContract()` -- δες `requiredFrom()` -- ώστε οι δύο διαδρομές να μη
     * μπορούν να διαφωνήσουν.
     *
     * @param list<array<string, mixed>> $contracts Γραμμές με `id` και `extra_json`.
     *
     * @return array<int, array{required: list<string>, collected: list<string>, complete: bool}>
     */
    public function forMany(array $contracts): array
    {
        $required = [];

        foreach ($contracts as $contract) {
            $id = (int) ($contract['id'] ?? 0);

            if ($id > 0) {
                $required[$id] = self::requiredFrom($contract);
            }
        }

        if ($required === []) {
            return [];
        }

        $kinds = array_values(SignatureRoles::kinds());
        $found = $this->files->signatureKindsFor(array_keys($required), $kinds);

        $state = [];

        foreach ($required as $id => $roles) {
            $collected = [];

            foreach (SignatureRoles::kinds() as $role => $kind) {
                if (in_array($role, $roles, true) && in_array($kind, $found[$id] ?? [], true)) {
                    $collected[] = $role;
                }
            }

            $state[$id] = [
                'required'  => $roles,
                'collected' => $collected,
                'complete'  => SignatureRoles::isComplete($roles, $collected),
            ];
        }

        return $state;
    }

    /**
     * Σβήνει την υπογραφή ΕΝΟΣ ρόλου -- το αρχείο της, με τα bytes του.
     *
     * Η υπογραφή του άλλου ρόλου (αν υπάρχει) δεν αγγίζεται. Ο καλών
     * (`SignLinkController`, όταν επιβεβαιωθεί ξανα-αποστολή σε ήδη
     * υπογεγραμμένο ρόλο) είναι υπεύθυνος να αποφασίσει αν χρειάζεται και
     * να μηδενίσει το `contracts.signed_at` -- αυτό εδώ ξέρει μόνο για
     * αρχεία, όχι για την κατάσταση της σύμβασης.
     */
    public function clearRole(int $contractId, string $role): void
    {
        $kind = SignatureRoles::kindFor($role);

        if ($kind === '') {
            return;
        }

        $this->files->deleteKind($contractId, $kind);
    }
}
