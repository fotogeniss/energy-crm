<?php

/**
 * Πόσες υπογραφές ζητά μια αίτηση COMBO -- και γιατί το ερώτημα έχει δικό του
 * αρχείο δοκιμών (04/09/2026, εγγραφή 226).
 *
 * Ο κανόνας γράφτηκε στο 3β-Β και είναι απλός: COMBO με **διαφορετικό** πελάτη
 * ενέργειας θέλει δύο υπογραφές, καθετί άλλο μία. Η υλοποίηση όμως διάβαζε το
 * `combo_energy_same` από το `extra_json`, και εκείνο το κλειδί ήταν
 * κατηγοριοποιημένο ως **προσωπικό** -- δηλαδή κρυπτογραφούνταν όταν άνοιγε το
 * `ECRM_ENCRYPT_PII`.
 *
 * Το αποτέλεσμα δεν ήταν σφάλμα αλλά **σιωπή**: ο έλεγχος `!== '0'` πάνω σε
 * ciphertext βγαίνει πάντα αληθής, οπότε κάθε COMBO δύο προσώπων διαβαζόταν ως
 * «ίδιο πρόσωπο» και ζητούσε μία υπογραφή. Το έντυπο θα έφευγε στον πάροχο με
 * την υπογραφή του ενός σε **δύο** γραμμές -- ακριβώς αυτό που ολόκληρο το
 * 3β-Β υπάρχει για να αποτρέψει.
 *
 * Χειρότερα, οι δύο διαδρομές διαφωνούσαν μεταξύ τους: το `SignLinkController`
 * διαβάζει μέσω `ContractDetails::findDetailed()`, που **αποκρυπτογραφεί**, και
 * έβλεπε σωστά δύο ρόλους· η δημόσια σελίδα υπογραφής (`ECRM_Tracking`) διαβάζει
 * τη στήλη ωμή και έβλεπε έναν. Ο πωλητής θα έστελνε δύο συνδέσμους και ο
 * πρώτος που θα υπέγραφε θα ολοκλήρωνε την αίτηση.
 *
 * Δεν το έπιασε καμία δοκιμή γιατί στο περιβάλλον ανάπτυξης η κρυπτογράφηση
 * είναι **κλειστή**: με κλειστή κρυπτογράφηση η τιμή είναι καθαρό «0» και όλα
 * δουλεύουν. Θα εμφανιζόταν την ημέρα του go-live, όταν ο ίδιος ο έλεγχος
 * υγείας λέει στον ιδιοκτήτη να την ανοίξει. Γι' αυτό οι δοκιμές εδώ γράφουν
 * τιμή σε **μορφή ciphertext** ρητά, αντί να ελπίζουν στη ρύθμιση του
 * περιβάλλοντος.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Files;
use EnergyCRM\Domain\Contract\SignatureRoles;
use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Infrastructure\SignatureState;
use EnergyCRM\Persistence\FileRepository;
use EnergyCRM\Persistence\Tables;

final class ComboSignatureRolesTest extends IntegrationTestCase
{
    private SignatureState $state;

    private int $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state  = new SignatureState(new FileRepository(ECRM_Files::dir()));
        $this->seller = $this->makePartner();
    }

    /**
     * Μια σύμβαση με συγκεκριμένο σάκο `extra`, γραμμένο **ωμά** στη στήλη.
     *
     * Ωμά επίτηδες: η δοκιμή θέλει να ελέγξει τι διαβάζει ο κώδικας από ό,τι
     * βρίσκει στη βάση, όχι τι γράφει η διαδρομή αποθήκευσης -- και η
     * ενδιαφέρουσα περίπτωση (ciphertext) δεν παράγεται καν όσο η
     * κρυπτογράφηση είναι κλειστή στο περιβάλλον δοκιμών.
     *
     * @param array<string, string> $extra
     */
    private function contractWithExtra(array $extra): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $this->seller,
            'status'          => 'new',
            'code'            => 'ΕΝ-COMBO-' . wp_rand(1000, 9999),
            'energy_type'     => 'mobile',
            'extra_json'      => (string) wp_json_encode($extra),
        ]);

        return (int) $wpdb->insert_id;
    }

    /** @return list<string> */
    private function requiredFor(int $contractId): array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT extra_json FROM %i WHERE id = %d',
                Tables::name(Tables::CONTRACTS),
                $contractId
            ),
            ARRAY_A
        );

        return $this->state->forContract($contractId, (array) $row)['required'];
    }

    // ── Ο κανόνας, στις καθαρές του περιπτώσεις ──────────────────────────

    public function testAComboWithADifferentEnergyCustomerNeedsBothSignatures(): void
    {
        $id = $this->contractWithExtra(['mobile_offer' => 'combo', 'combo_energy_same' => '0']);

        self::assertSame([SignatureRoles::MOBILE, SignatureRoles::ENERGY], $this->requiredFor($id));
    }

    public function testAComboWithTheSamePersonNeedsOneSignature(): void
    {
        $id = $this->contractWithExtra(['mobile_offer' => 'combo', 'combo_energy_same' => '1']);

        self::assertSame([SignatureRoles::MOBILE], $this->requiredFor($id));
    }

    /**
     * Αίτηση γραμμένη πριν καν υπάρξει το πεδίο: απούσα τιμή σημαίνει «ίδιο
     * πρόσωπο», ό,τι ίσχυε τότε. Δεν αναδρομικά δύο υπογραφές.
     */
    public function testAContractWithoutTheFlagAtAllNeedsOneSignature(): void
    {
        $id = $this->contractWithExtra(['mobile_offer' => 'combo']);

        self::assertSame([SignatureRoles::MOBILE], $this->requiredFor($id));
    }

    public function testANonComboOfferNeverNeedsASecondSignature(): void
    {
        $id = $this->contractWithExtra(['mobile_offer' => 'family', 'combo_energy_same' => '0']);

        self::assertSame([SignatureRoles::MOBILE], $this->requiredFor($id));
    }

    // ── Το ίδιο το σφάλμα του (226) ──────────────────────────────────────

    /**
     * **Η δοκιμή που έλειπε.**
     *
     * Πριν τη διόρθωση αυτή περνούσε από τον έλεγχο `!== '0'` ως αληθής --
     * «ίδιο πρόσωπο» -- και η αίτηση ζητούσε μία υπογραφή. Τώρα η
     * μη-αναγνώσιμη τιμή δεν διαβάζεται σιωπηλά: ζητούνται και οι δύο.
     */
    public function testAnUnreadableFlagIsNotSilentlyReadAsTheSamePerson(): void
    {
        $ciphertext = FieldCipher::PREFIX . base64_encode('δεν διαβάζεται από εδώ');

        self::assertTrue(FieldCipher::isEncrypted($ciphertext), 'Το fixture δεν μοιάζει καν κρυπτογραφημένο.');

        $id = $this->contractWithExtra([
            'mobile_offer'      => 'combo',
            'combo_energy_same' => $ciphertext,
        ]);

        self::assertSame(
            [SignatureRoles::MOBILE, SignatureRoles::ENERGY],
            $this->requiredFor($id),
            'Κρυπτογραφημένη σημαία διαβάστηκε ως «ίδιο πρόσωπο» -- το έντυπο θα έφευγε μισοϋπογεγραμμένο.'
        );
    }

    /**
     * Η ρίζα, ελεγμένη εκεί που ζει: το κλειδί δεν επιτρέπεται να ξαναγίνει
     * «προσωπικό», γιατί τότε ξανακρυπτογραφείται και το σφάλμα επιστρέφει.
     * Το ίδιο ελέγχεται και ως μονάδα (`ProviderFormFieldsTest`)· εδώ μένει
     * δίπλα στη συνέπειά του, ώστε όποιος το αλλάξει να δει τι σπάει.
     */
    public function testTheFlagStaysReadableBecauseItDecidesHowManySignaturesAreNeeded(): void
    {
        self::assertFalse(
            \EnergyCRM\Domain\Forms\ProviderFormFields::isPersonalInput('combo_energy_same'),
            'Το combo_energy_same ξανάγινε προσωπικό -- θα κρυπτογραφείται και το COMBO θα ζητά μία υπογραφή.'
        );
    }

    // ── Οι δύο διαδρομές πρέπει να συμφωνούν ─────────────────────────────

    /**
     * Το `SignLinkController` διαβάζει αποκρυπτογραφημένα, η δημόσια σελίδα
     * υπογραφής ωμά. Αν οι δύο διαφωνήσουν, ο πωλητής στέλνει δύο συνδέσμους
     * και ο πρώτος υπογράφων κλείνει την αίτηση. Ίδια είσοδος, ίδια απάντηση.
     */
    public function testTheDecryptedAndRawPathsAgree(): void
    {
        $extra = ['mobile_offer' => 'combo', 'combo_energy_same' => '0'];
        $id    = $this->contractWithExtra($extra);

        $raw       = $this->requiredFor($id);
        $decrypted = $this->state->forContract($id, ['extra_json' => (string) wp_json_encode($extra)])['required'];

        self::assertSame($raw, $decrypted);
    }
}
