<?php

/**
 * Η συνήθεια του πωλητή, μετρημένη πάνω σε πραγματικές γραμμές.
 *
 * Το `UsualChoiceTest` της unit σουίτας φυλάει το **κατώφλι**· εδώ φυλάγονται
 * τα τρία πράγματα που μόνο η βάση μπορεί να πει: ότι μετριούνται οι σωστές
 * γραμμές (όχι τα προσχέδια), ότι μετριέται το σωστό **παράθυρο** (οι
 * τελευταίες, όχι όλη η ιστορία), και ότι δεν διαρρέει η συνήθεια άλλου
 * πωλητή -- που θα ήταν και το μόνο σφάλμα εδώ με συνέπεια στην εμβέλεια.
 *
 * **Οι πάροχοι και τα προγράμματα φτιάχνονται αληθινά, όχι με φτιαχτά id.** Τα
 * `contracts.provider_id` και `contracts.program_id` έχουν foreign key προς
 * τους δικούς τους πίνακες (`AddForeignKeys`), και η MySQL απορρίπτει
 * **σιωπηλά** το insert με ανύπαρκτο id, γιατί το `IntegrationTestCase` έχει
 * κάνει `hide_errors()`. Το μάθημα είναι της (165) και κόστισε δύο αποτυχίες.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use EnergyCRM\Access\UserScope;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Providers\Domain\UsualChoice;
use EnergyCRM\Providers\Persistence\UsualChoiceRepository;
use WP_REST_Request;

final class UsualChoiceIntegrationTest extends IntegrationTestCase
{
    private ContractRepository $contracts;

    private UsualChoiceRepository $usual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contracts = new ContractRepository();
        $this->usual     = new UsualChoiceRepository();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    /** Η βασική διαδρομή: τρεις ίδιες αιτήσεις είναι συνήθεια. */
    public function testAClearHabitIsFound(): void
    {
        $partner  = $this->makePartner();
        $provider = $this->makeProvider('alpha');
        $program  = $this->makeProgram($provider, 'power');

        $this->fileContracts($partner, 3, $provider, 'power', $program);

        $found = $this->usual->forPartner($partner)->toArray();

        self::assertNotNull($found, 'Τρεις ίδιες αιτήσεις δεν αναγνωρίστηκαν ως συνήθεια.');
        self::assertSame($provider, $found['provider_id']);
        self::assertSame('power', $found['energy_type']);
        self::assertSame($program, $found['program_id']);
        self::assertSame(3, $found['times']);
        self::assertSame(3, $found['of']);
    }

    /** Δύο δεν είναι συνήθεια -- το κατώφλι ισχύει και πάνω σε αληθινές γραμμές. */
    public function testTwoIsNotAHabit(): void
    {
        $partner  = $this->makePartner();
        $provider = $this->makeProvider('alpha');

        $this->fileContracts($partner, 2, $provider, 'power', $this->makeProgram($provider, 'power'));

        self::assertNull($this->usual->forPartner($partner)->toArray());
    }

    /**
     * Τα προσχέδια δεν μετρούν.
     *
     * Ένα `draft` μπορεί να άνοιξε κατά λάθος, με τον πρώτο πάροχο της λίστας.
     * Αν μετρούσε, η πρώτη μέρα κάθε πωλητή θα του πρότεινε τον πάροχο που
     * απλώς πάτησε ψάχνοντας.
     */
    public function testDraftsDoNotCount(): void
    {
        $partner  = $this->makePartner();
        $provider = $this->makeProvider('alpha');

        $this->fileContracts($partner, 5, $provider, 'power', $this->makeProgram($provider, 'power'), 'draft');

        self::assertNull($this->usual->forPartner($partner)->toArray());
    }

    /** Ο πολυπληθέστερος συνδυασμός κερδίζει, όχι ο πιο πρόσφατος. */
    public function testTheMostFrequentCombinationWins(): void
    {
        $partner = $this->makePartner();
        $often   = $this->makeProvider('alpha');
        $rarely  = $this->makeProvider('beta');

        $this->fileContracts($partner, 5, $often, 'power', $this->makeProgram($often, 'power'));
        $this->fileContracts($partner, 3, $rarely, 'gas', $this->makeProgram($rarely, 'gas'));

        $found = $this->usual->forPartner($partner)->toArray();

        self::assertNotNull($found);
        self::assertSame($often, $found['provider_id'], 'Κέρδισε ο πιο πρόσφατος αντί για τον πολυπληθέστερο.');
        self::assertSame(5, $found['times']);
        self::assertSame(8, $found['of'], 'Το «από πόσες» πρέπει να μετρά ΟΛΟ το δείγμα, όχι τη νικήτρια ομάδα.');
    }

    /**
     * Μετρώνται μόνο οι τελευταίες `SAMPLE` -- όχι όλη η ιστορία του.
     *
     * Είναι ο λόγος που το `LIMIT` ζει ΜΕΣΑ στο υποερώτημα. Με το `LIMIT` απ'
     * έξω, ο πάροχος που ο πωλητής σταμάτησε να δουλεύει πριν από έναν χρόνο
     * θα του προτεινόταν για πάντα, αρκεί να τον είχε δουλέψει αρκετά τότε.
     */
    public function testOnlyTheRecentWindowCounts(): void
    {
        $partner = $this->makePartner();
        $old     = $this->makeProvider('alpha');
        $now     = $this->makeProvider('beta');

        // Παλιά συνήθεια, αρκετά δυνατή ώστε να περνούσε το κατώφλι μόνη της.
        $this->fileContracts($partner, 4, $old, 'power', $this->makeProgram($old, 'power'));

        // Και από πάνω της, ένα ολόκληρο παράθυρο με τη νέα.
        $this->fileContracts($partner, UsualChoice::SAMPLE, $now, 'gas', $this->makeProgram($now, 'gas'));

        $found = $this->usual->forPartner($partner)->toArray();

        self::assertNotNull($found);
        self::assertSame($now, $found['provider_id'], 'Η παλιά συνήθεια επιβίωσε έξω από το παράθυρο.');
        self::assertSame(UsualChoice::SAMPLE, $found['of']);
    }

    /**
     * Η συνήθεια ενός πωλητή δεν φαίνεται σε άλλον.
     *
     * Δεν υπάρχει `UserScope` στο repository επειδή το ερώτημα δέχεται έναν και
     * μόνο `partner_user_id`. Αυτό το test είναι η απόδειξη ότι αυτό αρκεί.
     */
    public function testAnotherPartnersHabitDoesNotLeak(): void
    {
        $mine     = $this->makePartner();
        $theirs   = $this->makePartner();
        $provider = $this->makeProvider('alpha');

        $this->fileContracts($theirs, 6, $provider, 'power', $this->makeProgram($provider, 'power'));

        self::assertNull($this->usual->forPartner($mine)->toArray());
    }

    /** Και ολόκληρη η καλωδίωση: το `/providers` το κουβαλάει στη φόρμα. */
    public function testTheCatalogueCarriesTheHabit(): void
    {
        $partner  = $this->makeCrmUser(Roles::SELLER);
        $provider = $this->makeProvider('alpha');

        $this->fileContracts($partner, 3, $provider, 'power', $this->makeProgram($provider, 'power'));

        wp_set_current_user($partner);

        $response = rest_do_request(new WP_REST_Request('GET', '/ecrm/v1/providers'));
        $data     = $response->get_data();

        self::assertSame(200, $response->get_status());
        self::assertArrayHasKey('usual', $data, 'Ο κατάλογος δεν κουβαλά καθόλου το «συνήθως».');
        self::assertIsArray($data['usual']);
        self::assertSame($provider, $data['usual']['provider_id']);
    }

    /** Γράφει `$howMany` όμοιες αιτήσεις στο όνομα του πωλητή. */
    private function fileContracts(
        int $partner,
        int $howMany,
        int $providerId,
        string $energyType,
        int $programId,
        string $status = 'new'
    ): void {
        $scope = UserScope::forSelf($partner);

        for ($i = 0; $i < $howMany; $i++) {
            $id = $this->contracts->create(
                [
                    'status'      => $status,
                    'provider_id' => $providerId,
                    'program_id'  => $programId,
                    'energy_type' => $energyType,
                ],
                $scope
            );

            self::assertGreaterThan(0, $id, 'Το fixture σύμβασης δεν αποθηκεύτηκε.');
        }
    }

    /** Πραγματικός πάροχος -- βλ. το docblock της κλάσης για το γιατί. */
    private function makeProvider(string $slug): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROVIDERS), [
            'slug' => 'ecrm-usual-' . $slug,
            'name' => 'Πάροχος ' . $slug,
        ]);

        $providerId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $providerId, 'Το fixture παρόχου δεν αποθηκεύτηκε.');

        return $providerId;
    }

    /** Πραγματικό πρόγραμμα του συγκεκριμένου παρόχου. */
    private function makeProgram(int $providerId, string $energyType): int
    {
        global $wpdb;

        $wpdb->insert(Tables::name(Tables::PROGRAMS), [
            'provider_id' => $providerId,
            'name'        => 'Πρόγραμμα ' . $energyType,
            'energy_type' => $energyType,
        ]);

        $programId = (int) $wpdb->insert_id;

        self::assertGreaterThan(0, $programId, 'Το fixture προγράμματος δεν αποθηκεύτηκε.');

        return $programId;
    }
}
