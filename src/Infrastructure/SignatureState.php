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
     * Το `mobile_offer`/`combo_energy_same` διαβάζονται απευθείας από το
     * `extra_json`, χωρίς αποκρυπτογράφηση: δεν είναι προσωπικά πεδία (βλ.
     * `ProviderFormFields::isPersonalInput()`) -- ίδιο μοτίβο με το `$xg()`
     * του `includes/class-ecrm-formfill.php`.
     *
     * @param array<string, mixed> $contract Πρέπει να έχει `extra_json` (raw στήλη).
     *
     * @return array{required: list<string>, collected: list<string>, complete: bool}
     */
    public function forContract(int $contractId, array $contract): array
    {
        $extra = json_decode((string) ($contract['extra_json'] ?? ''), true);
        $extra = is_array($extra) ? $extra : [];

        $offer = (string) ($extra['mobile_offer'] ?? '');
        // Προεπιλογή '1' -- ίδιο πρόσωπο -- ίδιο fallback με το formfill.php:
        // μια σύμβαση χωρίς αυτό το κλειδί (πριν το COMBO καν υπάρξει) δεν
        // πρέπει να διαβαστεί σαν να έχει δύο πρόσωπα.
        $same = ((string) ($extra['combo_energy_same'] ?? '1')) !== '0';

        $required = SignatureRoles::requiredFor($offer, $same);

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
